package capture

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

// RecordedExchange represents a real HTTP exchange captured during Laravel tests or dev traffic.
type RecordedExchange struct {
	Method          string            `json:"method"`
	URI             string            `json:"uri"`
	RequestHeaders  map[string]string `json:"request_headers,omitempty"`
	RequestBody     string            `json:"request_body,omitempty"`
	StatusCode      int               `json:"status_code"`
	ResponseHeaders map[string]string `json:"response_headers,omitempty"`
	ResponseBody    string            `json:"response_body,omitempty"`
	TestName        string            `json:"test_name,omitempty"`
}

var sensitiveKeys = map[string]bool{
	"password":              true,
	"password_confirmation": true,
	"current_password":      true,
	"new_password":          true,
	"token":                 true,
	"access_token":          true,
	"refresh_token":         true,
	"secret":                true,
	"client_secret":         true,
	"api_key":               true,
	"authorization":         true,
	"cookie":                true,
	"set-cookie":            true,
	"credit_card":           true,
	"card_number":           true,
	"cvv":                   true,
	"otp":                   true,
}

// RedactExchange masks sensitive tokens, passwords, and headers before persisting.
func RedactExchange(ex RecordedExchange) RecordedExchange {
	if len(ex.RequestHeaders) > 0 {
		clean := make(map[string]string, len(ex.RequestHeaders))
		for k, v := range ex.RequestHeaders {
			if sensitiveKeys[strings.ToLower(k)] {
				clean[k] = "Bearer {{token}}"
			} else {
				clean[k] = v
			}
		}
		ex.RequestHeaders = clean
	}

	if len(ex.ResponseHeaders) > 0 {
		clean := make(map[string]string, len(ex.ResponseHeaders))
		for k, v := range ex.ResponseHeaders {
			if sensitiveKeys[strings.ToLower(k)] {
				clean[k] = "[REDACTED]"
			} else {
				clean[k] = v
			}
		}
		ex.ResponseHeaders = clean
	}

	ex.RequestBody = redactJSONString(ex.RequestBody)
	ex.ResponseBody = redactJSONString(ex.ResponseBody)
	return ex
}

func redactJSONString(raw string) string {
	trimmed := strings.TrimSpace(raw)
	if trimmed == "" || (!strings.HasPrefix(trimmed, "{") && !strings.HasPrefix(trimmed, "[")) {
		return raw
	}
	var parsed any
	if err := json.Unmarshal([]byte(trimmed), &parsed); err != nil {
		return raw
	}
	cleaned := redactValue(parsed)
	out, err := json.MarshalIndent(cleaned, "", "  ")
	if err != nil {
		return raw
	}
	return string(out)
}

func redactValue(v any) any {
	switch val := v.(type) {
	case map[string]any:
		out := make(map[string]any, len(val))
		for k, child := range val {
			if sensitiveKeys[strings.ToLower(k)] {
				out[k] = "[REDACTED]"
			} else {
				out[k] = redactValue(child)
			}
		}
		return out
	case []any:
		out := make([]any, len(val))
		for i, child := range val {
			out[i] = redactValue(child)
		}
		return out
	default:
		return v
	}
}

// ListenUnixSocket starts a non-blocking NDJSON Unix Domain Socket listener that records traffic to disk.
func ListenUnixSocket(ctx context.Context, socketPath, outputFile string) error {
	_ = os.Remove(socketPath)
	if err := os.MkdirAll(filepath.Dir(socketPath), 0755); err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(outputFile), 0755); err != nil {
		return err
	}

	listener, err := net.Listen("unix", socketPath)
	if err != nil {
		return fmt.Errorf("failed to bind unix socket %s: %w", socketPath, err)
	}
	defer func() {
		listener.Close()
		_ = os.Remove(socketPath)
	}()

	file, err := os.OpenFile(outputFile, os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0644)
	if err != nil {
		return err
	}
	defer file.Close()

	var writeMu sync.Mutex

	go func() {
		<-ctx.Done()
		listener.Close()
	}()

	for {
		conn, err := listener.Accept()
		if err != nil {
			if ctx.Err() != nil {
				return nil
			}
			continue
		}

		go func(c net.Conn) {
			defer c.Close()
			scanner := bufio.NewScanner(c)
			scanner.Buffer(make([]byte, 64*1024), 4*1024*1024)
			for scanner.Scan() {
				line := strings.TrimSpace(scanner.Text())
				if line == "" {
					continue
				}
				var ex RecordedExchange
				if err := json.Unmarshal([]byte(line), &ex); err != nil {
					continue
				}
				clean := RedactExchange(ex)
				encoded, err := json.Marshal(clean)
				if err != nil {
					continue
				}
				writeMu.Lock()
				_, _ = file.Write(append(encoded, '\n'))
				writeMu.Unlock()
			}
		}(conn)
	}
}

type FuseStats struct {
	RecordingsRead int `json:"recordings_read"`
	MatchedRoutes  int `json:"matched_routes"`
	ExamplesAdded  int `json:"examples_added"`
}

// FuseRecordings merges real captured runtime exchanges into a Postman collection.
func FuseRecordings(col postman.Collection, recordingsPath string) (postman.Collection, FuseStats, error) {
	stats := FuseStats{}
	file, err := os.Open(recordingsPath)
	if err != nil {
		if os.IsNotExist(err) {
			return col, stats, nil
		}
		return col, stats, err
	}
	defer file.Close()

	// Map: "METHOD /norm/path" -> statusCode -> RecordedExchange
	grouped := make(map[string]map[int]RecordedExchange)

	scanner := bufio.NewScanner(file)
	scanner.Buffer(make([]byte, 64*1024), 4*1024*1024)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			continue
		}
		var ex RecordedExchange
		if err := json.Unmarshal([]byte(line), &ex); err != nil {
			continue
		}
		stats.RecordingsRead++
		sig := postman.NormalizeSignature(ex.Method, ex.URI)
		if grouped[sig] == nil {
			grouped[sig] = make(map[int]RecordedExchange)
		}
		grouped[sig][ex.StatusCode] = ex
	}

	col.Item = fuseRecursive(col.Item, grouped, &stats)
	return col, stats, nil
}

func fuseRecursive(items []postman.Item, grouped map[string]map[int]RecordedExchange, stats *FuseStats) []postman.Item {
	out := make([]postman.Item, len(items))
	for i, it := range items {
		if it.IsFolder() {
			it.Item = fuseRecursive(it.Item, grouped, stats)
			out[i] = it
			continue
		}

		sig := postman.NormalizeSignature(it.Request.Method, it.Request.URL.Raw())
		byStatus, ok := grouped[sig]
		if !ok || len(byStatus) == 0 {
			out[i] = it
			continue
		}

		stats.MatchedRoutes++
		existingRuntimeCodes := make(map[int]bool)
		for code, ex := range byStatus {
			existingRuntimeCodes[code] = true
			statusText := http.StatusText(code)
			if statusText == "" {
				statusText = "Response"
			}
			label := fmt.Sprintf("[Runtime %d] %s", code, statusText)
			if ex.TestName != "" {
				label = fmt.Sprintf("[Runtime %d] %s", code, ex.TestName)
			}

			var respHeaders []postman.Header
			for k, v := range ex.ResponseHeaders {
				respHeaders = append(respHeaders, postman.Header{Key: k, Value: v})
			}

			origReq := *it.Request
			if ex.RequestBody != "" {
				if origReq.Body != nil && origReq.Body.Mode == "raw" {
					bodyCopy := *origReq.Body
					bodyCopy.Raw = ex.RequestBody
					origReq.Body = &bodyCopy
				} else if origReq.Body == nil {
					origReq.Body = &postman.Body{Mode: "raw", Raw: ex.RequestBody}
				}
			}

			runtimeResp := postman.Response{
				Name:            label,
				OriginalRequest: &origReq,
				Status:          statusText,
				Code:            code,
				PostmanPreview:  "json",
				Header:          respHeaders,
				Body:            ex.ResponseBody,
			}

			// Replace previous runtime example with the same code, or prepend fresh runtime evidence
			replaced := false
			for idx, existing := range it.Response {
				if existing.Code == code && strings.HasPrefix(existing.Name, "[Runtime ") {
					it.Response[idx] = runtimeResp
					replaced = true
					break
				}
			}
			if !replaced {
				it.Response = append([]postman.Response{runtimeResp}, it.Response...)
				stats.ExamplesAdded++
			}
		}

		// Remove synthetic fallback 200 responses if real runtime 200/201 evidence was captured
		if existingRuntimeCodes[200] || existingRuntimeCodes[201] {
			filtered := make([]postman.Response, 0, len(it.Response))
			for _, r := range it.Response {
				if !strings.HasPrefix(r.Name, "[Runtime ") && strings.Contains(strings.ToLower(r.Name), "fallback") {
					continue
				}
				filtered = append(filtered, r)
			}
			it.Response = filtered
		}

		out[i] = it
	}
	return out
}
