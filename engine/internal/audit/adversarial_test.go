package audit

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

func TestAuditDetectsSQLTraceLeakIn200ResponseAndHandlesTimeouts(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/api/users/1":
			// Subtle bug: returns HTTP 200 but leaks SQLSTATE inside body
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"data":null,"debug":"SQLSTATE[42S22]: Column not found: 1054 Unknown column"}`))
		case "/api/slow":
			time.Sleep(400 * time.Millisecond)
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer srv.Close()

	col := postman.Collection{
		Info: postman.Info{Name: "Adversarial Audit"},
		Item: []postman.Item{
			{
				Name:    "GET User",
				Request: &postman.Request{Method: "GET", URL: postman.URLValue{RawString: "{{base_url}}/api/users/:id", IsString: true}},
			},
			{
				Name:    "GET Slow",
				Request: &postman.Request{Method: "GET", URL: postman.URLValue{RawString: "{{base_url}}/api/slow", IsString: true}},
			},
		},
	}

	report := RunAudit(context.Background(), col, AuditOptions{
		BaseURL:     srv.URL,
		Concurrency: 4,
		ChaosMode:   true,
		Timeout:     150 * time.Millisecond,
	})

	if report.CriticalCount != 1 {
		t.Fatalf("expected 1 CRITICAL trace leak finding, got %+v", report)
	}
	if report.WarningCount != 1 {
		t.Fatalf("expected 1 WARNING timeout finding, got %+v", report)
	}
}
