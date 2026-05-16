#!/usr/bin/env bash
# Stop the local wp-env environment (keeps data on disk).
# Use `npx @wordpress/env destroy` for a full wipe.

set -euo pipefail
cd "$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
npx --yes @wordpress/env stop
