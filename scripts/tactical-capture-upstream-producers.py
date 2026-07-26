#!/usr/bin/env python3
"""Capture Tactical RMM producer field tuples VERBATIM from a pinned upstream clone.

Writes tests/Fixtures/tactical/upstream_producers.json — the vendor's own
field lists (serializer Meta tuples, the calculate_agent_checks summary keys,
model column names), so TacticalSchemaDriftTest can prove every field SoundPSA
relies on against the vendor's source rather than against a hand-typed
fixture (CLAUDE.md "Vendor response shapes" / psa-0pb9m R2 finding d).

Usage:
    git clone --depth 1 https://github.com/amidaware/tacticalrmm /tmp/tacticalrmm
    python3 scripts/tactical-capture-upstream-producers.py /tmp/tacticalrmm

Then commit the refreshed JSON together with any EXPECTED_* list changes in
TacticalSchemaDriftTest, and update the _meta commit pins in BOTH
tests/Fixtures/tactical fixtures.
"""

import json
import re
import subprocess
import sys
from pathlib import Path

if len(sys.argv) != 2:
    sys.exit(__doc__)

clone = Path(sys.argv[1])
api = clone / "api" / "tacticalrmm"
if not api.is_dir():
    sys.exit(f"{api} does not exist — pass the tacticalrmm clone root")

commit = subprocess.run(
    ["git", "-C", str(clone), "log", "-1", "--format=%H %cs"],
    capture_output=True, text=True, check=True,
).stdout.strip()


def serializer_meta_fields(path: Path, cls: str) -> list[str]:
    """The quoted entries of a serializer class's Meta `fields` tuple/list."""
    src = path.read_text()
    match = re.search(rf"class {cls}\(.*?\n(.*?)(?=\nclass |\Z)", src, re.S)
    if not match:
        sys.exit(f"{cls} not found in {path}")
    meta = re.search(r"fields\s*=\s*[\[\(](.*?)[\]\)]", match.group(1), re.S)
    if not meta:
        sys.exit(f"{cls}.Meta.fields not found in {path}")
    return re.findall(r"[\"']([^\"']+)[\"']", meta.group(1))


def model_columns(path: Path, cls: str) -> list[str]:
    """Column-ish attribute names of a Django model class (models.*/ArrayField)."""
    src = path.read_text()
    match = re.search(rf"class {cls}\(.*?\n(.*?)(?=\nclass |\Z)", src, re.S)
    if not match:
        sys.exit(f"{cls} not found in {path}")
    return re.findall(r"^    (\w+)\s*=\s*(?:models\.|ArrayField)", match.group(1), re.M)


def summary_keys(path: Path) -> list[str]:
    """Keys of the `ret = {...}` literal in calculate_agent_checks."""
    src = path.read_text()
    match = re.search(r"def calculate_agent_checks\(.*?ret = \{(.*?)\}", src, re.S)
    if not match:
        sys.exit(f"calculate_agent_checks ret dict not found in {path}")
    return re.findall(r"[\"'](\w+)[\"']\s*:", match.group(1))


producers = {
    "_meta": {
        "description": (
            "Field tuples captured VERBATIM from the amidaware/tacticalrmm source at the "
            "pinned commit below, by scripts/tactical-capture-upstream-producers.py. "
            "TacticalSchemaDriftTest proves every field SoundPSA relies on is present in "
            "these vendor tuples — our expectations are checked against the vendor's own "
            "producer, not against a fixture we authored (CLAUDE.md vendor-shape rules)."
        ),
        "upstream_repo": "https://github.com/amidaware/tacticalrmm",
        "upstream_commit": commit.split()[0],
        "upstream_commit_date": commit.split()[1],
        "refresh_command": "python3 scripts/tactical-capture-upstream-producers.py <clone-path>",
    },
    "agent_table_serializer_fields": {
        "source": "api/tacticalrmm/agents/serializers.py class AgentTableSerializer Meta.fields (the GET agents/ list row)",
        "fields": serializer_meta_fields(api / "agents" / "serializers.py", "AgentTableSerializer"),
    },
    "agent_hostname_serializer_fields": {
        "source": "api/tacticalrmm/agents/serializers.py class AgentHostnameSerializer Meta.fields (the `agents` rows of GET automation/policies/{pk}/related/)",
        "fields": serializer_meta_fields(api / "agents" / "serializers.py", "AgentHostnameSerializer"),
    },
    "policy_related_serializer_fields": {
        "source": "api/tacticalrmm/automation/serializers.py class PolicyRelatedSerializer Meta.fields (GET automation/policies/{pk}/related/)",
        "fields": serializer_meta_fields(api / "automation" / "serializers.py", "PolicyRelatedSerializer"),
    },
    "agent_checks_summary_keys": {
        "source": "api/tacticalrmm/agents/utils.py calculate_agent_checks() ret dict (the per-agent `checks` summary on both agent endpoints)",
        "fields": summary_keys(api / "agents" / "utils.py"),
    },
    "check_result_model_columns": {
        "source": "api/tacticalrmm/checks/models.py class CheckResult (CheckSerializer.get_check_result emits CheckResultSerializer fields=__all__, or {} when no result row exists — checks/serializers.py)",
        "fields": model_columns(api / "checks" / "models.py", "CheckResult"),
    },
    "script_model_columns": {
        "source": "api/tacticalrmm/scripts/models.py class Script (script catalog rows; shell + supported_platforms are the platform-guard inputs)",
        "fields": model_columns(api / "scripts" / "models.py", "Script"),
    },
}

out = Path(__file__).resolve().parent.parent / "tests" / "Fixtures" / "tactical" / "upstream_producers.json"
out.write_text(json.dumps(producers, indent=2) + "\n")
print(f"wrote {out} @ {commit}")
for key, value in producers.items():
    if key != "_meta":
        print(f"  {key}: {len(value['fields'])} fields")
