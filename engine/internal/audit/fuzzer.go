package audit

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"sync"
	"time"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

type AuditOptions struct {
	BaseURL     string
	AuthToken   string
	Concurrency int
	ChaosMode   bool
	Timeout     time.Duration
}

type Finding struct {
	Severity   string `json:"severity"` // CRITICAL, WARNING, PASS
	Category   string `json:"category"` // SERVER_CRASH_500, AUTH_BYPASS, TRACE_LEAK, CONTRACT_OK
	Method     string `json:"method"`
	Endpoint   string `json:"endpoint"`
	StatusCode int    `json:"status_code"`
	LatencyMs  int64  `json:"latency_ms"`
	Detail     string `json:"detail"`
}

type AuditReport struct {
	BaseURL        string    `json:"base_url"`
	TotalEndpoints int       `json:"total_endpoints"`
	TotalProbes    int       `json:"total_probes"`
	CriticalCount  int       `json:"critical_count"`
	WarningCount   int       `json:"warning_count"`
	PassedCount    int       `json:"passed_count"`
	DurationMs     int64     `json:"duration_ms"`
	Findings       []Finding `json:"findings"`
}

type probeTask struct {
	category    string
	method      string
	displayPath string
	fullURL     string
	headers     map[string]string
	body        string
}

var (
	colonParamRe = regexp.MustCompile(`:([a-zA-Z0-9_]+)`)
	braceVarRe   = regexp.MustCompile(`\{\{([a-zA-Z0-9_]+)\}\}`)
)

// RunAudit executes concurrent security, schema, and chaos fuzzing probes against a live server.
func RunAudit(ctx context.Context, col postman.Collection, opts AuditOptions) AuditReport {
	start := time.Now()
	if opts.Concurrency <= 0 {
		opts.Concurrency = 10
	}
	if opts.Timeout <= 0 {
		opts.Timeout = 10 * time.Second
	}

	baseURL := strings.TrimRight(opts.BaseURL, "/")
	var items []postman.Item
	flattenItems(col.Item, &items)

	var tasks []probeTask
	for _, it := range items {
		if it.Request == nil {
			continue
		}
		tasks = append(tasks, buildProbesForRequest(*it.Request, baseURL, opts)...)
	}

	client := &http.Client{
		Timeout: opts.Timeout,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}

	taskCh := make(chan probeTask, len(tasks))
	for _, t := range tasks {
		taskCh <- t
	}
	close(taskCh)

	var mu sync.Mutex
	var findings []Finding
	var wg sync.WaitGroup

	for w := 0; w < opts.Concurrency; w++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for task := range taskCh {
				f := executeProbe(ctx, client, task)
				mu.Lock()
				findings = append(findings, f)
				mu.Unlock()
			}
		}()
	}
	wg.Wait()

	report := AuditReport{
		BaseURL:        baseURL,
		TotalEndpoints: len(items),
		TotalProbes:    len(tasks),
		DurationMs:     time.Since(start).Milliseconds(),
		Findings:       findings,
	}

	for _, f := range findings {
		switch f.Severity {
		case "CRITICAL":
			report.CriticalCount++
		case "WARNING":
			report.WarningCount++
		default:
			report.PassedCount++
		}
	}

	return report
}

func flattenItems(items []postman.Item, out *[]postman.Item) {
	for _, it := range items {
		if it.IsFolder() {
			flattenItems(it.Item, out)
		} else {
			*out = append(*out, it)
		}
	}
}

func buildProbesForRequest(req postman.Request, baseURL string, opts AuditOptions) []probeTask {
	raw := req.URL.Raw()
	resolved := strings.ReplaceAll(raw, "{{base_url}}", baseURL)
	resolved = braceVarRe.ReplaceAllString(resolved, "1")
	if !strings.HasPrefix(resolved, "http") {
		resolved = baseURL + "/" + strings.TrimLeft(resolved, "/")
	}
	if parsedURL, err := url.Parse(resolved); err == nil {
		parsedURL.Path = colonParamRe.ReplaceAllString(parsedURL.Path, "1")
		resolved = parsedURL.String()
	}

	displayPath := strings.TrimPrefix(resolved, baseURL)
	if displayPath == "" {
		displayPath = "/"
	}

	baseHeaders := make(map[string]string)
	hasAuth := false
	for _, h := range req.Header {
		if h.Disabled {
			continue
		}
		val := h.Value
		if strings.EqualFold(h.Key, "Authorization") {
			hasAuth = true
			if opts.AuthToken != "" {
				val = "Bearer " + opts.AuthToken
			}
		}
		baseHeaders[h.Key] = val
	}

	bodyStr := ""
	if req.Body != nil && req.Body.Mode == "raw" {
		bodyStr = req.Body.Raw
	}

	var probes []probeTask

	// 1. Auth Guard Probe: ensure protected routes reject unauthenticated requests
	if hasAuth {
		noAuthHeaders := make(map[string]string, len(baseHeaders))
		for k, v := range baseHeaders {
			if !strings.EqualFold(k, "Authorization") {
				noAuthHeaders[k] = v
			}
		}
		probes = append(probes, probeTask{
			category:    "AUTH_GUARD",
			method:      req.Method,
			displayPath: displayPath,
			fullURL:     resolved,
			headers:     noAuthHeaders,
			body:        bodyStr,
		})
	}

	// 2. Standard Schema Probe
	probes = append(probes, probeTask{
		category:    "SCHEMA_PROBE",
		method:      req.Method,
		displayPath: displayPath,
		fullURL:     resolved,
		headers:     baseHeaders,
		body:        bodyStr,
	})

	// 3. Chaos Type-Mutation Probe for mutating endpoints
	if opts.ChaosMode && (req.Method == http.MethodPost || req.Method == http.MethodPut || req.Method == http.MethodPatch) && bodyStr != "" {
		mutated := mutateJSONPayload(bodyStr)
		probes = append(probes, probeTask{
			category:    "CHAOS_FUZZ",
			method:      req.Method,
			displayPath: displayPath,
			fullURL:     resolved,
			headers:     baseHeaders,
			body:        mutated,
		})
	}

	return probes
}

func mutateJSONPayload(raw string) string {
	var obj map[string]any
	if err := json.Unmarshal([]byte(raw), &obj); err != nil {
		return `{"__lpe_chaos__": [null, true, -999999999999]}`
	}
	mutated := make(map[string]any, len(obj))
	idx := 0
	for k := range obj {
		switch idx % 4 {
		case 0:
			mutated[k] = nil
		case 1:
			mutated[k] = []any{"unexpected_array", 12345}
		case 2:
			mutated[k] = map[string]any{"nested": true}
		case 3:
			mutated[k] = strings.Repeat("X", 2048)
		}
		idx++
	}
	out, err := json.Marshal(mutated)
	if err != nil {
		return "{}"
	}
	return string(out)
}

func executeProbe(ctx context.Context, client *http.Client, task probeTask) Finding {
	var bodyReader io.Reader
	if task.body != "" {
		bodyReader = bytes.NewBufferString(task.body)
	}

	req, err := http.NewRequestWithContext(ctx, task.method, task.fullURL, bodyReader)
	if err != nil {
		return Finding{
			Severity: "WARNING",
			Category: "REQUEST_BUILD_ERROR",
			Method:   task.method,
			Endpoint: task.displayPath,
			Detail:   err.Error(),
		}
	}

	for k, v := range task.headers {
		req.Header.Set(k, v)
	}

	t0 := time.Now()
	resp, err := client.Do(req)
	latency := time.Since(t0).Milliseconds()
	if err != nil {
		return Finding{
			Severity:  "WARNING",
			Category:  "NETWORK_ERROR",
			Method:    task.method,
			Endpoint:  task.displayPath,
			LatencyMs: latency,
			Detail:    err.Error(),
		}
	}
	defer resp.Body.Close()

	respBytes, _ := io.ReadAll(io.LimitReader(resp.Body, 16*1024))
	respText := string(respBytes)

	// Check 1: Unhandled 500 Server Error
	if resp.StatusCode >= 500 {
		return Finding{
			Severity:   "CRITICAL",
			Category:   "SERVER_CRASH_500",
			Method:     task.method,
			Endpoint:   task.displayPath,
			StatusCode: resp.StatusCode,
			LatencyMs:  latency,
			Detail:     fmt.Sprintf("[%s] Returned HTTP %d — unhandled server exception on input.", task.category, resp.StatusCode),
		}
	}

	// Check 2: Stack trace or SQL error leak even in 4xx/2xx responses
	if strings.Contains(respText, "SQLSTATE[") || strings.Contains(respText, "Whoops\\") || strings.Contains(respText, "ErrorException") {
		return Finding{
			Severity:   "CRITICAL",
			Category:   "TRACE_LEAK",
			Method:     task.method,
			Endpoint:   task.displayPath,
			StatusCode: resp.StatusCode,
			LatencyMs:  latency,
			Detail:     "Response leaks internal SQL or PHP stack trace details.",
		}
	}

	// Check 3: Auth Guard verification
	if task.category == "AUTH_GUARD" {
		if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
			return Finding{
				Severity:   "PASS",
				Category:   "AUTH_GUARD_OK",
				Method:     task.method,
				Endpoint:   task.displayPath,
				StatusCode: resp.StatusCode,
				LatencyMs:  latency,
				Detail:     "Protected endpoint properly rejected unauthenticated request.",
			}
		}
		if resp.StatusCode >= 200 && resp.StatusCode < 300 {
			return Finding{
				Severity:   "CRITICAL",
				Category:   "AUTH_BYPASS",
				Method:     task.method,
				Endpoint:   task.displayPath,
				StatusCode: resp.StatusCode,
				LatencyMs:  latency,
				Detail:     fmt.Sprintf("Endpoint declares auth middleware but returned HTTP %d without a token!", resp.StatusCode),
			}
		}
	}

	return Finding{
		Severity:   "PASS",
		Category:   task.category + "_OK",
		Method:     task.method,
		Endpoint:   task.displayPath,
		StatusCode: resp.StatusCode,
		LatencyMs:  latency,
		Detail:     fmt.Sprintf("[%s] Handled cleanly with HTTP %d.", task.category, resp.StatusCode),
	}
}
