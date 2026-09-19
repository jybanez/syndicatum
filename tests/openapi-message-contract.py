"""Validate real Project API test responses against the V1 OpenAPI schemas.

Requires PyYAML and jsonschema. Pass --php when php on PATH is not PHP 8.2+.
"""

import argparse
import json
import os
from pathlib import Path
import subprocess
import tempfile

import jsonschema
import yaml


ROOT = Path(__file__).resolve().parents[1]


def normalize_nullable(value):
    if isinstance(value, list):
        return [normalize_nullable(item) for item in value]
    if not isinstance(value, dict):
        return value
    normalized = {key: normalize_nullable(item) for key, item in value.items() if key != "nullable"}
    if value.get("nullable") is True:
        if isinstance(normalized.get("type"), str):
            normalized["type"] = [normalized["type"], "null"]
        else:
            normalized = {"anyOf": [normalized, {"type": "null"}]}
    return normalized


def resolve_schema_references(value, schemas):
    if isinstance(value, list):
        return [resolve_schema_references(item, schemas) for item in value]
    if not isinstance(value, dict):
        return value
    reference = value.get("$ref")
    if reference is not None:
        prefix = "#/components/schemas/"
        if not reference.startswith(prefix):
            raise ValueError(f"Unsupported schema reference: {reference}")
        return resolve_schema_references(schemas[reference[len(prefix):]], schemas)
    return {key: resolve_schema_references(item, schemas) for key, item in value.items()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php", help="PHP 8.2+ executable")
    args = parser.parse_args()
    specification = normalize_nullable(yaml.safe_load((ROOT / "docs/openapi-v1.yaml").read_text(encoding="utf-8")))
    schemas = specification["components"]["schemas"]
    expected_responses = {
        ("/api/v1/projects.php", "get", "200"): "ProjectListResponse",
        ("/api/v1/project.php", "get", "200"): "ProjectContextResponse",
        ("/api/v1/project-participants.php", "get", "200"): "ParticipantListResponse",
        ("/api/v1/project-messages.php", "get", "200"): "MessagePageResponse",
        ("/api/v1/project-messages.php", "post", "201"): "MessageWriteResponse",
        ("/api/v1/project-messages.php", "post", "200"): "MessageWriteResponse",
        ("/api/v1/project-message.php", "get", "200"): "MessageResponse",
        ("/api/v1/project-message.php", "patch", "200"): "MessageResponse",
        ("/api/v1/project-message.php", "delete", "200"): "MessageResponse",
        ("/api/v1/project-message-acknowledge.php", "post", "200"): "MessageResponse",
    }
    for (path, method, status), schema_name in expected_responses.items():
        actual = specification["paths"][path][method]["responses"][status]["content"]["application/json"]["schema"]["$ref"]
        expected = f"#/components/schemas/{schema_name}"
        if actual != expected:
            raise SystemExit(f"OpenAPI {method.upper()} {path} {status} uses {actual}, expected {expected}")
    with tempfile.TemporaryDirectory(prefix="syndicatum-contract-") as temporary:
        capture = Path(temporary) / "responses.json"
        environment = os.environ.copy()
        environment["SYNDICATUM_CONTRACT_CAPTURE"] = str(capture)
        result = subprocess.run([args.php, str(ROOT / "tests/project-api.php")], cwd=ROOT, env=environment, check=False)
        if result.returncode != 0:
            raise SystemExit(result.returncode)
        samples = json.loads(capture.read_text(encoding="utf-8"))
    required = {"ProjectListResponse", "ProjectContextResponse", "ParticipantListResponse", "MessageWriteResponse", "MessagePageResponse", "MessageResponse", "ApiError"}
    seen = {sample["schema"] for sample in samples}
    if not required.issubset(seen):
        raise SystemExit("Missing contract samples: " + ", ".join(sorted(required - seen)))
    sampled_responses = {
        (sample["path"], sample["method"], str(sample["status"]))
        for sample in samples
    }
    missing_responses = set(expected_responses) - sampled_responses
    if missing_responses:
        raise SystemExit("Missing operation/status samples: " + ", ".join(
            f"{method.upper()} {path} {status}"
            for path, method, status in sorted(missing_responses)
        ))
    for index, sample in enumerate(samples, 1):
        schema = resolve_schema_references(schemas[sample["schema"]], schemas)
        validator = jsonschema.Draft7Validator(schema, format_checker=jsonschema.FormatChecker())
        errors = list(validator.iter_errors(sample["body"]))
        if errors:
            error = errors[0]
            raise SystemExit(f"Sample {index} ({sample['schema']}) failed at {list(error.path)}: {error.message}")
        response = specification["paths"][sample["path"]][sample["method"]]["responses"][str(sample["status"])]
        if "$ref" in response:
            prefix = "#/components/responses/"
            if not response["$ref"].startswith(prefix):
                raise SystemExit(f"Unsupported response reference: {response['$ref']}")
            response = specification["components"]["responses"][response["$ref"][len(prefix):]]
        declared = response["content"]["application/json"]["schema"]["$ref"]
        expected = f"#/components/schemas/{sample['schema']}"
        if declared != expected:
            raise SystemExit(f"Sample {index} response uses {declared}, expected {expected}")
    print(f"Validated {len(samples)} real Project API responses across {len(sampled_responses)} OpenAPI operation/status pairs.")


if __name__ == "__main__":
    main()
