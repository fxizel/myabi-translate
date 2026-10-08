#!/usr/bin/env python3
"""Audit DEVCONF input files without modifying them or displaying cell values.

Run from any directory with ``python path/to/scripts/audit_devconf.py``.
Use ``--directory PATH`` to audit another directory containing the seven files.
All record counts exclude the header. A duplicate means an occurrence after
the first occurrence of the same key; it is not a count of duplicate groups.
Case-insensitive counts use Python str.casefold(), not a presumed myABI rule.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import sys
from pathlib import Path


FORMATS = (
    ("01-DEVCONF-NLS-MRIC.csv", ("Name",)),
    ("02-DEVCONF-NLS-MONOMOT.csv", ("Name",)),
    ("03-DEVCONF-NLS-SERVER.csv", ("Name",)),
    (
        "04-DEVCONF-NLS-FORM.csv",
        ("Form Type", "Form Template Name", "Control Name", "Column Name"),
    ),
    (
        "05-DEVCONF-NLS-INCIDENTWORKFLOW.csv",
        ("Workflow", "Control type", "Control Name", "Column"),
    ),
    ("06-DEVCONF-NLS-LAWCATALOG.csv", ("ID#",)),
    (
        "07-DEVCONF-INCIDENTCODE.csv",
        ("GROUPTYPE", "CODEVALUE", "MASTERTYPE", "MASTERVALUE"),
    ),
)


class DigestWriter:
    """Consume csv.writer output in memory without retaining or saving it."""

    def __init__(self) -> None:
        self.digest = hashlib.sha256()
        self.byte_count = 0

    def write(self, value: str) -> int:
        encoded = value.encode("cp1252", errors="strict")
        self.digest.update(encoded)
        self.byte_count += len(encoded)
        return len(value)


def audit_file(path: Path, key_columns: tuple[str, ...], quote_all: bool) -> dict:
    # Hash exactly the same bytes that are subsequently decoded and parsed.
    # newline="" preserves both CRLF and LF within cell values.
    with path.open("rb") as source:
        digest = hashlib.file_digest(source, "sha256")
        byte_count = source.tell()

    regenerated = DigestWriter()
    writer = csv.writer(
        regenerated,
        delimiter=";",
        quotechar='"',
        doublequote=True,
        lineterminator="\r\n",
        quoting=csv.QUOTE_ALL if quote_all else csv.QUOTE_MINIMAL,
    )
    exact_keys: set[tuple[str, ...]] = set()
    folded_keys: set[tuple[str, ...]] = set()
    record_count = exact_duplicates = folded_duplicates = multiline_records = 0
    empty_key_records = wrong_column_records = 0

    with path.open("r", encoding="cp1252", errors="strict", newline="") as source:
        reader = csv.reader(source, delimiter=";", quotechar='"', strict=True)
        header = next(reader)
        folded_header = [column.casefold() for column in header]
        key_indices = [folded_header.index(column.casefold()) for column in key_columns]
        writer.writerow(header)
        for row in reader:
            record_count += 1
            writer.writerow(row)
            if len(row) != len(header):
                wrong_column_records += 1
                continue
            key = tuple(row[index] for index in key_indices)
            folded_key = tuple(value.casefold() for value in key)
            exact_duplicates += key in exact_keys
            folded_duplicates += folded_key in folded_keys
            exact_keys.add(key)
            folded_keys.add(folded_key)
            empty_key_records += any(not value for value in key)
            multiline_records += any("\r" in value or "\n" in value for value in row)

    return {
        "file": path.name,
        "bytes": byte_count,
        "sha256": digest.hexdigest(),
        "columns": len(header),
        "records": record_count,
        "wrong_column_records": wrong_column_records,
        "duplicate_records_exact": exact_duplicates,
        "duplicate_records_casefold": folded_duplicates,
        "records_with_empty_key_components": empty_key_records,
        "multiline_records": multiline_records,
        "roundtrip_quoting": "QUOTE_ALL" if quote_all else "QUOTE_MINIMAL",
        "roundtrip_bytes": regenerated.byte_count,
        "roundtrip_sha256": regenerated.digest.hexdigest(),
        "roundtrip_identical": (
            byte_count == regenerated.byte_count
            and digest.digest() == regenerated.digest.digest()
        ),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--directory",
        type=Path,
        default=Path(__file__).resolve().parents[1] / "data" / "imports",
        help="directory containing the seven known DEVCONF filenames",
    )
    args = parser.parse_args()
    csv.field_size_limit(16 * 1024 * 1024)
    reports = []
    errors = []
    for index, (filename, key_columns) in enumerate(FORMATS):
        try:
            reports.append(audit_file(args.directory / filename, key_columns, index < 6))
        except (OSError, UnicodeError, csv.Error, ValueError, StopIteration) as error:
            # Exception messages can contain input values or filesystem details.
            errors.append({"file": filename, "error_type": type(error).__name__})

    print(json.dumps({
        "encoding": "cp1252",
        "delimiter": ";",
        "duplicate_definition": "occurrences after the first, not duplicate groups",
        "casefold_note": "diagnostic only; myABI case sensitivity is unconfirmed",
        "files": reports,
        "totals": {
            "files_read": len(reports),
            "bytes": sum(report["bytes"] for report in reports),
            "records": sum(report["records"] for report in reports),
        },
        "errors": errors,
    }, indent=2))
    return int(bool(errors) or any(report["wrong_column_records"] for report in reports))


if __name__ == "__main__":
    sys.exit(main())
