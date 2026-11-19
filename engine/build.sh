#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p ../bin

# Build native local binary
go build -ldflags="-s -w" -o ../bin/lpe-engine ./cmd/lpe-engine

# Cross-compile release binaries if --all is passed
if [[ "${1:-}" == "--all" ]]; then
  GOOS=linux   GOARCH=amd64 go build -ldflags="-s -w" -o ../bin/lpe-engine-linux-amd64 ./cmd/lpe-engine
  GOOS=linux   GOARCH=arm64 go build -ldflags="-s -w" -o ../bin/lpe-engine-linux-arm64 ./cmd/lpe-engine
  GOOS=darwin  GOARCH=amd64 go build -ldflags="-s -w" -o ../bin/lpe-engine-darwin-amd64 ./cmd/lpe-engine
  GOOS=darwin  GOARCH=arm64 go build -ldflags="-s -w" -o ../bin/lpe-engine-darwin-arm64 ./cmd/lpe-engine
  GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o ../bin/lpe-engine-windows-amd64.exe ./cmd/lpe-engine
fi

ls -lh ../bin/
