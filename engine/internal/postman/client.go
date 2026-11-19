package postman

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

const defaultPostmanBaseURL = "https://api.getpostman.com"

type CloudClient struct {
	APIKey  string
	BaseURL string
	HTTP    *http.Client
}

func NewCloudClient(apiKey string) *CloudClient {
	return &CloudClient{
		APIKey:  strings.TrimSpace(apiKey),
		BaseURL: defaultPostmanBaseURL,
		HTTP: &http.Client{
			Timeout: 30 * time.Second,
		},
	}
}

type collectionSummary struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	UID  string `json:"uid"`
}

// FindCollectionUIDByName locates an existing collection UID matching the collection name.
func (c *CloudClient) FindCollectionUIDByName(name, workspaceID string) (string, error) {
	endpoint := c.BaseURL + "/collections"
	if workspaceID != "" {
		endpoint += "?workspace=" + workspaceID
	}

	req, err := http.NewRequest(http.MethodGet, endpoint, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("X-Api-Key", c.APIKey)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode >= 400 {
		body, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("postman API list failed (%d): %s", resp.StatusCode, string(body))
	}

	var payload struct {
		Collections []collectionSummary `json:"collections"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return "", err
	}

	target := strings.ToLower(strings.TrimSpace(name))
	for _, col := range payload.Collections {
		if strings.ToLower(strings.TrimSpace(col.Name)) == target {
			if col.UID != "" {
				return col.UID, nil
			}
			return col.ID, nil
		}
	}
	return "", nil
}

// FetchCollection retrieves an existing collection from Postman Cloud.
func (c *CloudClient) FetchCollection(uid string) (*Collection, error) {
	req, err := http.NewRequest(http.MethodGet, c.BaseURL+"/collections/"+uid, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("X-Api-Key", c.APIKey)

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode == http.StatusNotFound {
		return nil, nil
	}
	if resp.StatusCode >= 400 {
		body, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("postman API fetch failed (%d): %s", resp.StatusCode, string(body))
	}

	var wrapper struct {
		Collection Collection `json:"collection"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&wrapper); err != nil {
		return nil, err
	}
	return &wrapper.Collection, nil
}

type UpsertResult struct {
	UID     string `json:"uid"`
	Name    string `json:"name"`
	Created bool   `json:"created"`
}

// UpsertCollection updates an existing collection via PUT or creates a new one via POST.
func (c *CloudClient) UpsertCollection(uid, workspaceID string, col Collection) (UpsertResult, error) {
	bodyBytes, err := json.Marshal(map[string]any{
		"collection": col,
	})
	if err != nil {
		return UpsertResult{}, err
	}

	method := http.MethodPost
	endpoint := c.BaseURL + "/collections"
	created := true

	if uid != "" {
		method = http.MethodPut
		endpoint = c.BaseURL + "/collections/" + uid
		created = false
	} else if workspaceID != "" {
		endpoint += "?workspace=" + workspaceID
	}

	req, err := http.NewRequest(method, endpoint, bytes.NewReader(bodyBytes))
	if err != nil {
		return UpsertResult{}, err
	}
	req.Header.Set("X-Api-Key", c.APIKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return UpsertResult{}, err
	}
	defer resp.Body.Close()

	respBytes, _ := io.ReadAll(resp.Body)
	if resp.StatusCode >= 400 {
		return UpsertResult{}, fmt.Errorf("postman API %s failed (%d): %s", method, resp.StatusCode, string(respBytes))
	}

	var wrapper struct {
		Collection collectionSummary `json:"collection"`
	}
	_ = json.Unmarshal(respBytes, &wrapper)

	finalUID := uid
	if wrapper.Collection.UID != "" {
		finalUID = wrapper.Collection.UID
	} else if wrapper.Collection.ID != "" {
		finalUID = wrapper.Collection.ID
	}

	return UpsertResult{
		UID:     finalUID,
		Name:    col.Info.Name,
		Created: created,
	}, nil
}
