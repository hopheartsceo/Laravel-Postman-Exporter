package capture

import (
	"context"
	"encoding/json"
	"net"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

func TestUnixSocketCaptureRedactionAndFusion(t *testing.T) {
	tmpDir := t.TempDir()
	sockPath := filepath.Join(tmpDir, "lpe.sock")
	recPath := filepath.Join(tmpDir, "recordings.ndjson")

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	errCh := make(chan error, 1)
	go func() {
		errCh <- ListenUnixSocket(ctx, sockPath, recPath)
	}()

	// Wait for socket
	var conn net.Conn
	var err error
	for i := 0; i < 40; i++ {
		conn, err = net.Dial("unix", sockPath)
		if err == nil {
			break
		}
		time.Sleep(20 * time.Millisecond)
	}
	if err != nil {
		t.Fatalf("failed to connect to unix socket: %v", err)
	}

	ex := RecordedExchange{
		Method:         "POST",
		URI:            "api/v1/login",
		RequestHeaders: map[string]string{"Authorization": "Bearer secret123", "Accept": "application/json"},
		RequestBody:    `{"email":"ahmad@wessaal.com","password":"MyPassword123"}`,
		StatusCode:     200,
		ResponseBody:   `{"token":"jwt_live_token_abc","user":{"id":1,"name":"Ahmad"}}`,
		TestName:       "User can login with valid credentials",
	}
	raw, _ := json.Marshal(ex)
	_, _ = conn.Write(append(raw, '\n'))
	_ = conn.Close()

	time.Sleep(80 * time.Millisecond)
	cancel()
	<-errCh

	col := postman.Collection{
		Info: postman.Info{Name: "Test API"},
		Item: []postman.Item{
			{
				Name: "POST Login",
				Request: &postman.Request{
					Method: "POST",
					URL:    postman.URLValue{RawString: "{{base_url}}/api/v1/login", IsString: true},
					Body:   &postman.Body{Mode: "raw", Raw: `{"email":"test@example.com","password":"secret"}`},
				},
				Response: []postman.Response{
					{Name: "200 Fallback", Code: 200, Body: `{"fallback":true}`},
				},
			},
		},
	}

	fused, stats, err := FuseRecordings(col, recPath)
	if err != nil {
		t.Fatalf("FuseRecordings failed: %v", err)
	}
	if stats.RecordingsRead != 1 || stats.MatchedRoutes != 1 || stats.ExamplesAdded != 1 {
		t.Fatalf("unexpected fuse stats: %+v", stats)
	}

	responses := fused.Item[0].Response
	if len(responses) != 1 {
		t.Fatalf("expected fallback 200 to be replaced by runtime 200, got %d responses", len(responses))
	}
	if !strings.Contains(responses[0].Body, "[REDACTED]") || strings.Contains(responses[0].Body, "jwt_live_token_abc") {
		t.Fatalf("expected token to be redacted in response body, got: %s", responses[0].Body)
	}
	if strings.Contains(responses[0].OriginalRequest.Body.Raw, "MyPassword123") {
		t.Fatalf("expected password to be redacted in request body")
	}
}
