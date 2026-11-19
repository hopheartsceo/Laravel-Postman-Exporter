package postman

import (
	"fmt"
	"regexp"
	"strings"
)

const autoWireMarker = "// [LPE-AUTO-WIRE]"

// MergeOptions controls how local AST collections merge with remote Postman collections.
type MergeOptions struct {
	AutoWireAuth       bool
	PreserveDeprecated bool
}

// MergeStats summarizes what changed during a smart merge.
type MergeStats struct {
	Added      int `json:"added"`
	Updated    int `json:"updated"`
	Preserved  int `json:"preserved_scripts"`
	Deprecated int `json:"deprecated"`
	AutoWired  int `json:"auto_wired"`
}

var (
	paramColonRegex  = regexp.MustCompile(`^:[a-zA-Z0-9_]+$`)
	paramBraceRegex  = regexp.MustCompile(`^\{\{?[a-zA-Z0-9_]+\}?\??\}$`)
	schemeHostRegex  = regexp.MustCompile(`^(https?://[^/]+|\{\{base_url\}\})`)
	inlineBraceRegex = regexp.MustCompile(`\{\{?[a-zA-Z0-9_]+\}?\??\}`)
)

// NormalizeSignature builds a canonical "METHOD /normalized/path" key for matching endpoints.
func NormalizeSignature(method, rawURL string) string {
	m := strings.ToUpper(strings.TrimSpace(method))
	path := strings.TrimSpace(rawURL)

	path = schemeHostRegex.ReplaceAllString(path, "")
	path = inlineBraceRegex.ReplaceAllString(path, ":param")
	if idx := strings.Index(path, "?"); idx != -1 {
		path = path[:idx]
	}
	path = strings.Trim(path, "/")

	if path == "" {
		return m + " /"
	}

	segments := strings.Split(path, "/")
	for i, seg := range segments {
		if paramColonRegex.MatchString(seg) || paramBraceRegex.MatchString(seg) {
			segments[i] = ":param"
		}
	}
	return m + " /" + strings.Join(segments, "/")
}

// SmartMerge merges freshly generated Laravel routes into an existing Postman collection
// without destroying manual test scripts, pre-request scripts, custom headers, or variables.
func SmartMerge(local Collection, remote *Collection, opts MergeOptions) (Collection, MergeStats) {
	stats := MergeStats{}

	if remote == nil {
		result := local
		if opts.AutoWireAuth {
			result.Item = applyAutoWireRecursive(result.Item, &stats)
		}
		stats.Added = countRequests(result.Item)
		return result, stats
	}

	merged := Collection{
		Info:                    local.Info,
		Event:                   remote.Event,
		Auth:                    remote.Auth,
		ProtocolProfileBehavior: remote.ProtocolProfileBehavior,
	}
	if remote.Info.PostmanID != "" {
		merged.Info.PostmanID = remote.Info.PostmanID
	}
	if remote.Info.ExporterID != "" {
		merged.Info.ExporterID = remote.Info.ExporterID
	}

	merged.Variable = mergeVariables(local.Variable, remote.Variable)

	remoteRequests := make(map[string]Item)
	remoteFolders := make(map[string]Item)
	indexRemoteItems(remote.Item, remoteRequests, remoteFolders)

	matchedSigs := make(map[string]bool)
	merged.Item = mergeItemsRecursive(local.Item, remoteRequests, remoteFolders, matchedSigs, opts, &stats)

	if opts.PreserveDeprecated {
		var deprecated []Item
		for sig, rItem := range remoteRequests {
			if !matchedSigs[sig] {
				depItem := rItem
				if !strings.HasPrefix(depItem.Name, "[DEPRECATED]") {
					depItem.Name = "[DEPRECATED] " + depItem.Name
				}
				deprecated = append(deprecated, depItem)
				stats.Deprecated++
			}
		}
		if len(deprecated) > 0 {
			merged.Item = append(merged.Item, Item{
				Name:        "Deprecated Routes",
				Description: "Endpoints preserved from Postman that no longer exist in Laravel routes.",
				Item:        deprecated,
			})
		}
	}

	return merged, stats
}

func indexRemoteItems(items []Item, reqMap map[string]Item, folderMap map[string]Item) {
	for _, it := range items {
		if it.IsFolder() {
			if it.Name != "Deprecated Routes" {
				folderMap[strings.ToLower(strings.TrimSpace(it.Name))] = it
				indexRemoteItems(it.Item, reqMap, folderMap)
			}
			continue
		}
		sig := NormalizeSignature(it.Request.Method, it.Request.URL.Raw())
		reqMap[sig] = it
	}
}

func mergeItemsRecursive(
	localItems []Item,
	remoteRequests map[string]Item,
	remoteFolders map[string]Item,
	matchedSigs map[string]bool,
	opts MergeOptions,
	stats *MergeStats,
) []Item {
	out := make([]Item, 0, len(localItems))

	for _, lItem := range localItems {
		if lItem.IsFolder() {
			folderKey := strings.ToLower(strings.TrimSpace(lItem.Name))
			mergedFolder := lItem
			if rFolder, ok := remoteFolders[folderKey]; ok {
				if rFolder.ID != "" {
					mergedFolder.ID = rFolder.ID
				}
				if len(rFolder.Event) > 0 {
					mergedFolder.Event = rFolder.Event
				}
				if len(rFolder.Auth) > 0 {
					mergedFolder.Auth = rFolder.Auth
				}
			}
			mergedFolder.Item = mergeItemsRecursive(lItem.Item, remoteRequests, remoteFolders, matchedSigs, opts, stats)
			out = append(out, mergedFolder)
			continue
		}

		sig := NormalizeSignature(lItem.Request.Method, lItem.Request.URL.Raw())
		rItem, exists := remoteRequests[sig]

		var finalItem Item
		if !exists {
			finalItem = lItem
			stats.Added++
		} else {
			matchedSigs[sig] = true
			finalItem = mergeSingleRequest(lItem, rItem, stats)
			stats.Updated++
		}

		if opts.AutoWireAuth {
			finalItem = autoWireRequest(finalItem, stats)
		}
		out = append(out, finalItem)
	}

	return out
}

func mergeSingleRequest(local, remote Item, stats *MergeStats) Item {
	merged := local
	if remote.ID != "" {
		merged.ID = remote.ID
	}
	if len(remote.Event) > 0 {
		merged.Event = remote.Event
		stats.Preserved++
	}
	if len(remote.Auth) > 0 {
		merged.Auth = remote.Auth
	}
	if len(remote.ProtocolProfileBehavior) > 0 {
		merged.ProtocolProfileBehavior = remote.ProtocolProfileBehavior
	}
	if len(remote.Request.Auth) > 0 {
		merged.Request.Auth = remote.Request.Auth
	}

	merged.Request.Header = mergeHeaders(local.Request.Header, remote.Request.Header)
	merged.Response = mergeResponses(local.Response, remote.Response)

	return merged
}

func mergeHeaders(local, remote []Header) []Header {
	seen := make(map[string]bool)
	out := make([]Header, 0, len(local)+len(remote))

	for _, h := range local {
		seen[strings.ToLower(h.Key)] = true
		out = append(out, h)
	}
	for _, h := range remote {
		if !seen[strings.ToLower(h.Key)] {
			out = append(out, h)
		}
	}
	return out
}

func mergeResponses(local, remote []Response) []Response {
	seen := make(map[string]bool)
	out := make([]Response, 0, len(local)+len(remote))

	for _, r := range local {
		key := fmt.Sprintf("%d:%s", r.Code, strings.ToLower(strings.TrimSpace(r.Name)))
		seen[key] = true
		out = append(out, r)
	}
	for _, r := range remote {
		key := fmt.Sprintf("%d:%s", r.Code, strings.ToLower(strings.TrimSpace(r.Name)))
		if !seen[key] {
			out = append(out, r)
		}
	}
	return out
}

func mergeVariables(local, remote []Variable) []Variable {
	remoteMap := make(map[string]Variable)
	for _, v := range remote {
		remoteMap[v.Key] = v
	}

	seen := make(map[string]bool)
	out := make([]Variable, 0, len(local)+len(remote))

	for _, lv := range local {
		seen[lv.Key] = true
		if rv, ok := remoteMap[lv.Key]; ok && rv.Value != "" && rv.Value != "your-auth-token-here" {
			out = append(out, rv)
		} else {
			out = append(out, lv)
		}
	}
	for _, rv := range remote {
		if !seen[rv.Key] {
			out = append(out, rv)
		}
	}
	return out
}

func applyAutoWireRecursive(items []Item, stats *MergeStats) []Item {
	out := make([]Item, len(items))
	for i, it := range items {
		if it.IsFolder() {
			it.Item = applyAutoWireRecursive(it.Item, stats)
			out[i] = it
		} else {
			out[i] = autoWireRequest(it, stats)
		}
	}
	return out
}

func autoWireRequest(it Item, stats *MergeStats) Item {
	if it.Request == nil {
		return it
	}

	// Skip if already auto-wired
	for _, ev := range it.Event {
		if ev.Script != nil {
			for _, line := range ev.Script.Exec {
				if strings.Contains(line, autoWireMarker) {
					return it
				}
			}
		}
	}

	sig := strings.ToLower(NormalizeSignature(it.Request.Method, it.Request.URL.Raw()))
	isAuthEndpoint := strings.HasPrefix(sig, "post ") &&
		(strings.HasSuffix(sig, "/login") ||
			strings.HasSuffix(sig, "/signin") ||
			strings.HasSuffix(sig, "/auth/token") ||
			strings.HasSuffix(sig, "/authenticate") ||
			strings.HasSuffix(sig, "/register"))

	if !isAuthEndpoint {
		return it
	}

	authScript := []string{
		autoWireMarker,
		"if (pm.response.code >= 200 && pm.response.code < 300) {",
		"    try {",
		"        const json = pm.response.json();",
		"        const token = json.token || json.access_token || (json.data && (json.data.token || json.data.access_token));",
		"        if (token) {",
		"            pm.collectionVariables.set('token', token);",
		"            console.log('LPE: Auth token automatically saved to {{token}}');",
		"        }",
		"    } catch (e) {}",
		"}",
	}

	for idx, ev := range it.Event {
		if ev.Listen == "test" && ev.Script != nil {
			it.Event[idx].Script.Exec = append(it.Event[idx].Script.Exec, authScript...)
			stats.AutoWired++
			return it
		}
	}

	it.Event = append(it.Event, Event{
		Listen: "test",
		Script: &Script{
			Type: "text/javascript",
			Exec: authScript,
		},
	})
	stats.AutoWired++
	return it
}

func countRequests(items []Item) int {
	total := 0
	for _, it := range items {
		if it.IsFolder() {
			total += countRequests(it.Item)
		} else {
			total++
		}
	}
	return total
}
