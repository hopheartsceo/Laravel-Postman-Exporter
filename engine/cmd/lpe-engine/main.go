package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/audit"
	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/capture"
	"github.com/hopheartsceo/laravel-postman-exporter/engine/internal/postman"
)

const engineVersion = "3.0.0"

func main() {
	if len(os.Args) < 2 {
		printUsage()
		os.Exit(1)
	}

	switch os.Args[1] {
	case "version":
		fmt.Printf(`{"version":"%s"}`+"\n", engineVersion)
	case "merge":
		runMerge(os.Args[2:])
	case "sync":
		runSync(os.Args[2:])
	case "listen":
		runListen(os.Args[2:])
	case "fuse":
		runFuse(os.Args[2:])
	case "audit":
		runAudit(os.Args[2:])
	default:
		printUsage()
		os.Exit(1)
	}
}

func printUsage() {
	fmt.Fprintln(os.Stderr, "Usage: lpe-engine <version|merge|sync|listen|fuse|audit> [flags]")
}

func runMerge(args []string) {
	fs := flag.NewFlagSet("merge", flag.ExitOnError)
	localPath := fs.String("local", "", "Path to freshly generated local Postman collection JSON")
	remotePath := fs.String("remote", "", "Optional path to existing Postman collection JSON")
	outPath := fs.String("out", "", "Output path for merged collection JSON")
	autoWire := fs.Bool("auto-wire", true, "Auto-inject token capture scripts on auth routes")
	preserveDep := fs.Bool("preserve-deprecated", true, "Preserve removed routes under Deprecated folder")
	_ = fs.Parse(args)

	localCol, err := readCollection(*localPath)
	failIfErr(err)

	var remoteCol *postman.Collection
	if *remotePath != "" {
		if _, statErr := os.Stat(*remotePath); statErr == nil {
			rc, err := readCollection(*remotePath)
			failIfErr(err)
			remoteCol = &rc
		}
	}

	merged, stats := postman.SmartMerge(localCol, remoteCol, postman.MergeOptions{
		AutoWireAuth:       *autoWire,
		PreserveDeprecated: *preserveDep,
	})

	if *outPath != "" {
		failIfErr(writeJSON(*outPath, merged))
	}

	out, _ := json.Marshal(map[string]any{
		"success": true,
		"stats":   stats,
	})
	fmt.Println(string(out))
}

func runSync(args []string) {
	fs := flag.NewFlagSet("sync", flag.ExitOnError)
	localPath := fs.String("local", "", "Path to local Postman collection JSON")
	apiKey := fs.String("api-key", "", "Postman API key")
	uid := fs.String("uid", "", "Existing Postman collection UID (optional, auto-detected by name if empty)")
	workspaceID := fs.String("workspace", "", "Postman Workspace ID (optional)")
	outPath := fs.String("out", "", "Optional path to write merged collection locally")
	autoWire := fs.Bool("auto-wire", true, "Auto-inject token capture scripts")
	preserveDep := fs.Bool("preserve-deprecated", true, "Preserve deprecated endpoints")
	_ = fs.Parse(args)

	if *apiKey == "" {
		failIfErr(fmt.Errorf("--api-key is required"))
	}

	localCol, err := readCollection(*localPath)
	failIfErr(err)

	client := postman.NewCloudClient(*apiKey)
	targetUID := *uid
	if targetUID == "" {
		foundUID, err := client.FindCollectionUIDByName(localCol.Info.Name, *workspaceID)
		failIfErr(err)
		targetUID = foundUID
	}

	var remoteCol *postman.Collection
	if targetUID != "" {
		remoteCol, err = client.FetchCollection(targetUID)
		failIfErr(err)
	}

	merged, stats := postman.SmartMerge(localCol, remoteCol, postman.MergeOptions{
		AutoWireAuth:       *autoWire,
		PreserveDeprecated: *preserveDep,
	})

	if *outPath != "" {
		failIfErr(writeJSON(*outPath, merged))
	}

	upsertRes, err := client.UpsertCollection(targetUID, *workspaceID, merged)
	failIfErr(err)

	out, _ := json.Marshal(map[string]any{
		"success": true,
		"upsert":  upsertRes,
		"stats":   stats,
	})
	fmt.Println(string(out))
}

func runListen(args []string) {
	fs := flag.NewFlagSet("listen", flag.ExitOnError)
	socketPath := fs.String("socket", "/tmp/lpe-recorder.sock", "Unix domain socket path")
	outPath := fs.String("out", "storage/app/lpe-recordings.ndjson", "NDJSON output file path")
	_ = fs.Parse(args)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	failIfErr(capture.ListenUnixSocket(ctx, *socketPath, *outPath))
}

func runFuse(args []string) {
	fs := flag.NewFlagSet("fuse", flag.ExitOnError)
	colPath := fs.String("collection", "", "Path to Postman collection JSON")
	recPath := fs.String("recordings", "", "Path to recorded NDJSON exchanges")
	outPath := fs.String("out", "", "Output path for fused collection JSON")
	_ = fs.Parse(args)

	col, err := readCollection(*colPath)
	failIfErr(err)

	fused, stats, err := capture.FuseRecordings(col, *recPath)
	failIfErr(err)

	target := *outPath
	if target == "" {
		target = *colPath
	}
	failIfErr(writeJSON(target, fused))

	out, _ := json.Marshal(map[string]any{
		"success": true,
		"stats":   stats,
	})
	fmt.Println(string(out))
}

func runAudit(args []string) {
	fs := flag.NewFlagSet("audit", flag.ExitOnError)
	colPath := fs.String("collection", "", "Path to Postman collection JSON")
	baseURL := fs.String("base-url", "http://127.0.0.1:8000", "Target server base URL")
	token := fs.String("token", "", "Bearer token for authenticated probes")
	concurrency := fs.Int("concurrency", 10, "Number of concurrent Go workers")
	chaos := fs.Bool("chaos", true, "Enable type-mutation chaos fuzzing probes")
	timeoutSec := fs.Int("timeout", 10, "Per-request timeout in seconds")
	_ = fs.Parse(args)

	col, err := readCollection(*colPath)
	failIfErr(err)

	report := audit.RunAudit(context.Background(), col, audit.AuditOptions{
		BaseURL:     *baseURL,
		AuthToken:   *token,
		Concurrency: *concurrency,
		ChaosMode:   *chaos,
		Timeout:     time.Duration(*timeoutSec) * time.Second,
	})

	out, _ := json.MarshalIndent(report, "", "  ")
	fmt.Println(string(out))

	if report.CriticalCount > 0 {
		os.Exit(2)
	}
}

func readCollection(path string) (postman.Collection, error) {
	var col postman.Collection
	if path == "" {
		return col, fmt.Errorf("collection path is required")
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return col, err
	}
	if err := json.Unmarshal(data, &col); err != nil {
		return col, err
	}
	return col, nil
}

func writeJSON(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "    ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, append(data, '\n'), 0644)
}

func failIfErr(err error) {
	if err != nil {
		out, _ := json.Marshal(map[string]any{
			"success": false,
			"error":   err.Error(),
		})
		fmt.Println(string(out))
		os.Exit(1)
	}
}
