package audit

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

func TestConcurrentAuditDetectsServerCrashAndAuthBypass(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/orders":
			if r.Method == http.MethodPost {
				// Simulate unhandled 500 crash on chaos payload
				w.WriteHeader(http.StatusInternalServerError)
				_, _ = w.Write([]byte(`{"message":"ErrorException: Trying to access array offset on value of type null"}`))
				return
			}
		case "/api/admin/stats":
			// Simulate forgotten auth middleware (returns 200 without Authorization header)
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"revenue":99999}`))
			return
		case "/api/ping":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"pong":true}`))
			return
		}
		w.WriteHeader(http.StatusNotFound)
	}))
	defer srv.Close()

	col := postman.Collection{
		Info: postman.Info{Name: "Audit Test"},
		Item: []postman.Item{
			{
				Name: "POST Orders",
				Request: &postman.Request{
					Method: "POST",
					URL:    postman.URLValue{RawString: "{{base_url}}/api/orders", IsString: true},
					Body:   &postman.Body{Mode: "raw", Raw: `{"amount":100}`},
				},
			},
			{
				Name: "GET Admin Stats",
				Request: &postman.Request{
					Method: "GET",
					URL:    postman.URLValue{RawString: "{{base_url}}/api/admin/stats", IsString: true},
					Header: []postman.Header{{Key: "Authorization", Value: "Bearer {{token}}"}},
				},
			},
			{
				Name: "GET Ping",
				Request: &postman.Request{
					Method: "GET",
					URL:    postman.URLValue{RawString: "{{base_url}}/api/ping", IsString: true},
				},
			},
		},
	}

	report := RunAudit(context.Background(), col, AuditOptions{
		BaseURL:     srv.URL,
		Concurrency: 4,
		ChaosMode:   true,
	})

	if report.CriticalCount < 2 {
		t.Fatalf("expected at least 2 critical findings (500 crash + auth bypass), got %+v", report)
	}
	if report.PassedCount < 1 {
		t.Fatalf("expected at least 1 passing probe, got %+v", report)
	}
}
