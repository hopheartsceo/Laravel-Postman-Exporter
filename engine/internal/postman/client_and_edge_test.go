package postman

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestSignatureNormalizationAndMergeIdempotency(t *testing.T) {
	cases := [][2]string{
		{NormalizeSignature("get", "{{base_url}}/api/v1/users/:id/posts/:post?page=2"), NormalizeSignature("GET", "/api/v1/users/{user}/posts/{post?}")},
		{NormalizeSignature("POST", "https://api.wessaal.com/api/auth/login/"), NormalizeSignature("post", "{{base_url}}/api/auth/login")},
	}
	for i, pair := range cases {
		if pair[0] != pair[1] {
			t.Fatalf("case %d: expected equal signatures, got %q vs %q", i, pair[0], pair[1])
		}
	}

	local := Collection{
		Info: Info{Name: "Wessaal API"},
		Item: []Item{
			{
				Name: "auth",
				Item: []Item{
					{
						Name: "POST Login",
						Request: &Request{
							Method: "POST",
							URL:    URLValue{RawString: "{{base_url}}/api/login", IsString: true},
						},
						Event: []Event{
							{Listen: "prerequest", Script: &Script{Exec: StringOrSlice{"console.log('pre');"}}},
							{Listen: "test", Script: &Script{Exec: StringOrSlice{"pm.test('custom', () => {});"}}},
						},
					},
				},
			},
		},
	}

	// Run SmartMerge 3 consecutive times to prove strict idempotency
	current := local
	for run := 1; run <= 3; run++ {
	snapshot := current
	merged, stats := SmartMerge(local, &snapshot, MergeOptions{AutoWireAuth: true, PreserveDeprecated: true})
		if run == 1 && stats.AutoWired != 1 {
			t.Fatalf("run 1: expected AutoWired=1, got %d", stats.AutoWired)
		}
		if run > 1 && stats.AutoWired != 0 {
			t.Fatalf("run %d: expected AutoWired=0 (idempotent), got %d", run, stats.AutoWired)
		}
		current = merged
	}

	login := current.Item[0].Item[0]
	if len(login.Event) != 2 {
		t.Fatalf("expected prerequest + test events preserved, got %d", len(login.Event))
	}
	testCode := strings.Join(login.Event[1].Script.Exec, "\n")
	if strings.Count(testCode, autoWireMarker) != 1 {
		t.Fatalf("expected auto-wire marker exactly once, got:\n%s", testCode)
	}
	if !strings.Contains(testCode, "pm.test('custom'") {
		t.Fatalf("expected existing test script to be preserved")
	}
}

func TestCloudClientLifecycleAndErrorHandling(t *testing.T) {
	var lastMethod string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-Api-Key") != "valid-key" {
			w.WriteHeader(http.StatusUnauthorized)
			_, _ = w.Write([]byte(`{"error":"invalid api key"}`))
			return
		}
		lastMethod = r.Method
		switch {
		case r.Method == http.MethodGet && r.URL.Path == "/collections":
			_ = json.NewEncoder(w).Encode(map[string]any{
				"collections": []map[string]string{
					{"id": "111", "uid": "owner-111", "name": "Wessaal API"},
				},
			})
		case r.Method == http.MethodGet && r.URL.Path == "/collections/owner-111":
			_ = json.NewEncoder(w).Encode(map[string]any{
				"collection": Collection{Info: Info{Name: "Wessaal API", PostmanID: "111"}},
			})
		case r.Method == http.MethodPut && r.URL.Path == "/collections/owner-111":
			_ = json.NewEncoder(w).Encode(map[string]any{
				"collection": map[string]string{"id": "111", "uid": "owner-111", "name": "Wessaal API"},
			})
		default:
			w.WriteHeader(http.StatusNotFound)
		}
	}))
	defer srv.Close()

	badClient := NewCloudClient("wrong-key")
	badClient.BaseURL = srv.URL
	if _, err := badClient.FindCollectionUIDByName("Wessaal API", ""); err == nil {
		t.Fatalf("expected error on 401 unauthorized")
	}

	client := NewCloudClient("valid-key")
	client.BaseURL = srv.URL

	uid, err := client.FindCollectionUIDByName("wessaal api", "")
	if err != nil || uid != "owner-111" {
		t.Fatalf("expected uid owner-111, got %q (err=%v)", uid, err)
	}

	fetched, err := client.FetchCollection(uid)
	if err != nil || fetched == nil || fetched.Info.PostmanID != "111" {
		t.Fatalf("unexpected fetched collection: %+v (err=%v)", fetched, err)
	}

	res, err := client.UpsertCollection(uid, "", *fetched)
	if err != nil || res.Created || lastMethod != http.MethodPut {
		t.Fatalf("expected PUT update, got method=%s res=%+v err=%v", lastMethod, res, err)
	}
}
