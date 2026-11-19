package capture

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

func TestHighConcurrencyUnixSocketAndNestedRedaction(t *testing.T) {
	tmpDir := t.TempDir()
	sockPath := filepath.Join(tmpDir, "stress.sock")
	recPath := filepath.Join(tmpDir, "stress.ndjson")

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	done := make(chan error, 1)
	go func() { done <- ListenUnixSocket(ctx, sockPath, recPath) }()

	for i := 0; i < 50; i++ {
		if c, err := net.Dial("unix", sockPath); err == nil {
			_ = c.Close()
			break
		}
		time.Sleep(15 * time.Millisecond)
	}

	const total = 150
	var wg sync.WaitGroup
	for i := 0; i < total; i++ {
		wg.Add(1)
		go func(idx int) {
			defer wg.Done()
			conn, err := net.DialTimeout("unix", sockPath, 2*time.Second)
			if err != nil {
				t.Errorf("worker %d dial failed: %v", idx, err)
				return
			}
			defer conn.Close()

			status := 200
			if idx%3 == 0 {
				status = 422
			}
			ex := RecordedExchange{
				Method:         "POST",
				URI:            "api/v1/orders",
				RequestHeaders: map[string]string{"AUTHORIZATION": "Bearer topsecret", "X-Api-Key": "key123"},
				RequestBody:    fmt.Sprintf(`{"items":[{"otp":"998877","nested":{"client_secret":"sec_%d"}}]}`, idx),
				StatusCode:     status,
				ResponseBody:   fmt.Sprintf(`{"order_id":%d,"refresh_token":"ref_%d"}`, idx, idx),
			}
			b, _ := json.Marshal(ex)
			_, _ = conn.Write(append(b, '\n'))
		}(i)
	}
	wg.Wait()
	time.Sleep(120 * time.Millisecond)
	cancel()
	<-done

	col := postman.Collection{
		Info: postman.Info{Name: "Stress API"},
		Item: []postman.Item{
			{
				Name:    "POST Orders",
				Request: &postman.Request{Method: "POST", URL: postman.URLValue{RawString: "{{base_url}}/api/v1/orders", IsString: true}},
			},
		},
	}

	fused, stats, err := FuseRecordings(col, recPath)
	if err != nil {
		t.Fatalf("FuseRecordings failed: %v", err)
	}
	if stats.RecordingsRead != total {
		t.Fatalf("expected all %d concurrent recordings intact, got %d", total, stats.RecordingsRead)
	}
	if len(fused.Item[0].Response) != 2 {
		t.Fatalf("expected 2 distinct runtime status examples (200 & 422), got %d", len(fused.Item[0].Response))
	}
	for _, r := range fused.Item[0].Response {
		if strings.Contains(r.Body, "ref_") || strings.Contains(r.OriginalRequest.Body.Raw, "998877") || strings.Contains(r.OriginalRequest.Body.Raw, "sec_") {
			t.Fatalf("secret leaked after redaction! resp=%s req=%s", r.Body, r.OriginalRequest.Body.Raw)
		}
	}
}
