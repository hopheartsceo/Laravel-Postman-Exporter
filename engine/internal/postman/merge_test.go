package postman

import (
	"strings"
	"testing"
)

func TestSmartMergePreservesScriptsVariablesAndAutoWiresAuth(t *testing.T) {
	local := Collection{
		Info: Info{Name: "Wessaal API", Schema: "https://schema.getpostman.com/json/collection/v2.1.0/collection.json"},
		Variable: []Variable{
			{Key: "base_url", Value: "http://localhost"},
			{Key: "token", Value: "your-auth-token-here"},
		},
		Item: []Item{
			{
				Name: "auth",
				Item: []Item{
					{
						Name: "POST Login",
						Request: &Request{
							Method: "POST",
							URL:    URLValue{RawString: "{{base_url}}/api/v1/login", IsString: true},
						},
					},
				},
			},
			{
				Name: "messages",
				Item: []Item{
					{
						Name: "GET Messages",
						Request: &Request{
							Method: "GET",
							URL:    URLValue{RawString: "{{base_url}}/api/v1/messages", IsString: true},
						},
					},
				},
			},
		},
	}

	remote := &Collection{
		Info: Info{Name: "Wessaal API", PostmanID: "pm-uuid-999"},
		Variable: []Variable{
			{Key: "token", Value: "real_team_jwt_token_123"},
			{Key: "tenant_id", Value: "42"},
		},
		Item: []Item{
			{
				Name: "messages",
				Item: []Item{
					{
						ID:   "item-msg-1",
						Name: "GET Messages",
						Request: &Request{
							Method: "GET",
							URL:    URLValue{RawString: "{{base_url}}/api/v1/messages", IsString: true},
							Header: []Header{{Key: "X-Custom-Team", Value: "frontend-v2"}},
						},
						Event: []Event{
							{
								Listen: "test",
								Script: &Script{Exec: StringOrSlice{"pm.test('status 200', () => pm.response.to.have.status(200));"}},
							},
						},
					},
					{
						Name: "DELETE Legacy Endpoint",
						Request: &Request{
							Method: "DELETE",
							URL:    URLValue{RawString: "{{base_url}}/api/v1/legacy-sms", IsString: true},
						},
					},
				},
			},
		},
	}

	merged, stats := SmartMerge(local, remote, MergeOptions{
		AutoWireAuth:       true,
		PreserveDeprecated: true,
	})

	if merged.Info.PostmanID != "pm-uuid-999" {
		t.Fatalf("expected PostmanID to be preserved, got %s", merged.Info.PostmanID)
	}
	if stats.Added != 1 || stats.Updated != 1 || stats.Preserved != 1 || stats.Deprecated != 1 || stats.AutoWired != 1 {
		t.Fatalf("unexpected merge stats: %+v", stats)
	}

	// Verify real token variable was preserved
	var foundToken, foundTenant bool
	for _, v := range merged.Variable {
		if v.Key == "token" && v.Value == "real_team_jwt_token_123" {
			foundToken = true
		}
		if v.Key == "tenant_id" && v.Value == "42" {
			foundTenant = true
		}
	}
	if !foundToken || !foundTenant {
		t.Fatalf("expected team variables to be preserved: %+v", merged.Variable)
	}

	// Verify login got auto-wired
	loginItem := merged.Item[0].Item[0]
	if len(loginItem.Event) == 0 || !strings.Contains(strings.Join(loginItem.Event[0].Script.Exec, "\n"), autoWireMarker) {
		t.Fatalf("expected login endpoint to be auto-wired with token script")
	}

	// Verify messages kept custom header and team test script
	msgItem := merged.Item[1].Item[0]
	if len(msgItem.Event) != 1 || len(msgItem.Request.Header) != 1 {
		t.Fatalf("expected custom header and test script to be preserved on GET Messages")
	}

	// Verify deprecated folder created
	depFolder := merged.Item[len(merged.Item)-1]
	if depFolder.Name != "Deprecated Routes" || len(depFolder.Item) != 1 {
		t.Fatalf("expected Deprecated Routes folder with 1 item, got %+v", depFolder)
	}
}
