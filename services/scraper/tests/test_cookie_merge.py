"""Tests for cookie merge utilities in scraper.py."""
import pytest
from src.scraper import (
    parse_cookie_pairs,
    merge_set_cookies,
    canonical_cookie_hash,
)


# =========================================================
# parse_cookie_pairs
# =========================================================


def test_parse_basic_cookies():
    result = parse_cookie_pairs("SPC_EC=abc; SPC_F=def")
    assert result == {"SPC_EC": "abc", "SPC_F": "def"}


def test_parse_value_with_equals():
    """Base64 tokens contain '=' characters."""
    result = parse_cookie_pairs("token=abc123==; SPC_EC=xyz")
    assert result == {"token": "abc123==", "SPC_EC": "xyz"}


def test_parse_deduplicates_last_wins():
    result = parse_cookie_pairs("SPC_EC=old; other=x; SPC_EC=new")
    assert result["SPC_EC"] == "new"
    assert result["other"] == "x"


def test_parse_empty_string():
    assert parse_cookie_pairs("") == {}


def test_parse_segments_without_equals_skipped():
    result = parse_cookie_pairs("SPC_EC=abc; novalue; SPC_F=def")
    assert result == {"SPC_EC": "abc", "SPC_F": "def"}


def test_parse_whitespace_handling():
    result = parse_cookie_pairs("  SPC_EC = abc ;  SPC_F = def  ")
    assert result == {"SPC_EC": "abc", "SPC_F": "def"}


# =========================================================
# canonical_cookie_hash
# =========================================================


def test_hash_order_insensitive():
    h1 = canonical_cookie_hash({"A": "1", "B": "2"})
    h2 = canonical_cookie_hash({"B": "2", "A": "1"})
    assert h1 == h2


def test_hash_changes_when_value_changes():
    h1 = canonical_cookie_hash({"SPC_EC": "old"})
    h2 = canonical_cookie_hash({"SPC_EC": "new"})
    assert h1 != h2


def test_hash_length():
    h = canonical_cookie_hash({"SPC_EC": "abc123"})
    assert len(h) == 12


# =========================================================
# merge_set_cookies
# =========================================================


def test_merge_no_set_cookie_headers():
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=abc; SPC_F=def",
        {"Content-Type": "application/json"},
    )
    assert changed is False
    assert "SPC_EC=abc" in merged


def test_merge_single_set_cookie():
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=old; SPC_F=keep",
        {"Set-Cookie": "SPC_EC=new; Path=/; HttpOnly"},
    )
    assert changed is True
    assert "SPC_EC=new" in merged
    assert "SPC_F=keep" in merged
    assert "SPC_EC=old" not in merged
    assert "Path" not in merged  # attributes stripped


def test_merge_multiple_set_cookies_newline_joined():
    """Some HTTP layers join multiple Set-Cookie into one header with newlines."""
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=old; SPC_F=old2",
        {"set-cookie": "SPC_EC=new1; Path=/\nSPC_F=new2; Path=/"},
    )
    assert changed is True
    assert "SPC_EC=new1" in merged
    assert "SPC_F=new2" in merged


def test_merge_case_insensitive_header_key():
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=old",
        {"set-cookie": "SPC_EC=new; Path=/"},
    )
    assert changed is True
    assert "SPC_EC=new" in merged


def test_merge_adds_new_cookie():
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=keep",
        {"Set-Cookie": "SPC_NEW=added; Path=/"},
    )
    assert changed is True
    assert "SPC_EC=keep" in merged
    assert "SPC_NEW=added" in merged


def test_merge_value_with_equals_in_set_cookie():
    """Set-Cookie values can contain '=' (e.g., base64 tokens)."""
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=old",
        {"Set-Cookie": "SPC_EC=abc123==; Path=/; HttpOnly"},
    )
    assert changed is True
    assert "SPC_EC=abc123==" in merged


def test_merge_identical_cookies_not_changed():
    merged, hash_, changed = merge_set_cookies(
        "SPC_EC=same; SPC_F=same2",
        {"Set-Cookie": "SPC_EC=same; Path=/\nSPC_F=same2; Path=/"},
    )
    assert changed is False


def test_merge_returns_sorted_output():
    merged, _, _ = merge_set_cookies(
        "Z=1; A=2",
        {"Set-Cookie": "M=3; Path=/"},
    )
    keys = [s.split("=")[0] for s in merged.split("; ")]
    assert keys == sorted(keys)
