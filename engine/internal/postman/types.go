package postman

import (
	"encoding/json"
	"fmt"
	"strings"
)

// StringOrSlice handles Postman fields that can be either a string or []string (e.g. script.exec, url.host).
type StringOrSlice []string

func (s *StringOrSlice) UnmarshalJSON(data []byte) error {
	if string(data) == "null" {
		*s = nil
		return nil
	}
	var slice []string
	if err := json.Unmarshal(data, &slice); err == nil {
		*s = slice
		return nil
	}
	var single string
	if err := json.Unmarshal(data, &single); err == nil {
		*s = strings.Split(single, "\n")
		return nil
	}
	return fmt.Errorf("postman: expected string or []string, got %s", string(data))
}

// URLObject represents a structured Postman v2.1 URL.
type URLObject struct {
	Raw      string        `json:"raw,omitempty"`
	Protocol string        `json:"protocol,omitempty"`
	Host     StringOrSlice `json:"host,omitempty"`
	Path     StringOrSlice `json:"path,omitempty"`
	Port     string        `json:"port,omitempty"`
	Query    []QueryParam  `json:"query,omitempty"`
	Hash     string        `json:"hash,omitempty"`
	Variable []Variable    `json:"variable,omitempty"`
}

// URLValue handles Postman URLs that may be serialized as either a raw string or a URLObject.
type URLValue struct {
	RawString string
	Object    *URLObject
	IsString  bool
}

func (u *URLValue) UnmarshalJSON(data []byte) error {
	if string(data) == "null" {
		return nil
	}
	var str string
	if err := json.Unmarshal(data, &str); err == nil {
		u.RawString = str
		u.IsString = true
		return nil
	}
	var obj URLObject
	if err := json.Unmarshal(data, &obj); err == nil {
		u.Object = &obj
		u.IsString = false
		return nil
	}
	return fmt.Errorf("postman: invalid url format: %s", string(data))
}

func (u URLValue) MarshalJSON() ([]byte, error) {
	if u.IsString {
		return json.Marshal(u.RawString)
	}
	if u.Object != nil {
		return json.Marshal(u.Object)
	}
	return []byte("null"), nil
}

func (u URLValue) Raw() string {
	if u.IsString {
		return u.RawString
	}
	if u.Object != nil {
		return u.Object.Raw
	}
	return ""
}

type Collection struct {
	Info                    Info            `json:"info"`
	Item                    []Item          `json:"item"`
	Event                   []Event         `json:"event,omitempty"`
	Variable                []Variable      `json:"variable,omitempty"`
	Auth                    json.RawMessage `json:"auth,omitempty"`
	ProtocolProfileBehavior json.RawMessage `json:"protocolProfileBehavior,omitempty"`
}

type Info struct {
	PostmanID   string `json:"_postman_id,omitempty"`
	Name        string `json:"name"`
	Description string `json:"description,omitempty"`
	Schema      string `json:"schema"`
	ExporterID  string `json:"_exporter_id,omitempty"`
}

type Item struct {
	ID                      string          `json:"id,omitempty"`
	Name                    string          `json:"name"`
	Description             string          `json:"description,omitempty"`
	Variable                []Variable      `json:"variable,omitempty"`
	Event                   []Event         `json:"event,omitempty"`
	Item                    []Item          `json:"item,omitempty"`
	Request                 *Request        `json:"request,omitempty"`
	Response                []Response      `json:"response,omitempty"`
	Auth                    json.RawMessage `json:"auth,omitempty"`
	ProtocolProfileBehavior json.RawMessage `json:"protocolProfileBehavior,omitempty"`
}

func (i Item) IsFolder() bool {
	return i.Request == nil
}

type Request struct {
	URL         URLValue        `json:"url"`
	Auth        json.RawMessage `json:"auth,omitempty"`
	Proxy       json.RawMessage `json:"proxy,omitempty"`
	Certificate json.RawMessage `json:"certificate,omitempty"`
	Method      string          `json:"method"`
	Description string          `json:"description,omitempty"`
	Header      []Header        `json:"header,omitempty"`
	Body        *Body           `json:"body,omitempty"`
}

type Header struct {
	Key         string `json:"key"`
	Value       string `json:"value"`
	Type        string `json:"type,omitempty"`
	Description string `json:"description,omitempty"`
	Disabled    bool   `json:"disabled,omitempty"`
}

type QueryParam struct {
	Key         string `json:"key"`
	Value       string `json:"value"`
	Description string `json:"description,omitempty"`
	Disabled    bool   `json:"disabled,omitempty"`
}

type Variable struct {
	ID          string `json:"id,omitempty"`
	Key         string `json:"key"`
	Value       string `json:"value"`
	Type        string `json:"type,omitempty"`
	Description string `json:"description,omitempty"`
	Disabled    bool   `json:"disabled,omitempty"`
}

type Body struct {
	Mode       string          `json:"mode"`
	Raw        string          `json:"raw,omitempty"`
	URLEncoded []FormParam     `json:"urlencoded,omitempty"`
	FormData   []FormParam     `json:"formdata,omitempty"`
	Options    json.RawMessage `json:"options,omitempty"`
	Disabled   bool            `json:"disabled,omitempty"`
}

type FormParam struct {
	Key         string          `json:"key"`
	Value       string          `json:"value,omitempty"`
	Src         json.RawMessage `json:"src,omitempty"`
	Type        string          `json:"type,omitempty"`
	Description string          `json:"description,omitempty"`
	Disabled    bool            `json:"disabled,omitempty"`
}

type Response struct {
	ID              string          `json:"id,omitempty"`
	Name            string          `json:"name"`
	OriginalRequest *Request        `json:"originalRequest,omitempty"`
	Status          string          `json:"status,omitempty"`
	Code            int             `json:"code,omitempty"`
	PostmanPreview  string          `json:"_postman_previewlanguage,omitempty"`
	Header          []Header        `json:"header,omitempty"`
	Cookie          json.RawMessage `json:"cookie,omitempty"`
	Body            string          `json:"body,omitempty"`
}

type Event struct {
	Listen   string  `json:"listen"`
	Script   *Script `json:"script,omitempty"`
	Disabled bool    `json:"disabled,omitempty"`
}

type Script struct {
	ID       string        `json:"id,omitempty"`
	Type     string        `json:"type,omitempty"`
	Exec     StringOrSlice `json:"exec,omitempty"`
	Src      string        `json:"src,omitempty"`
	Name     string        `json:"name,omitempty"`
}
