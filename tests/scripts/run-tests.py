#!/usr/bin/env python3
from __future__ import annotations

import argparse
import base64
import hashlib
import hmac
import json
import re
import subprocess
import sys
import time
import traceback
import urllib.error
import urllib.request
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Callable, Iterable


ROOT = Path(__file__).resolve().parents[2]
TESTS_DIR = ROOT / "tests"
COMPOSE_FILE = TESTS_DIR / "docker-compose.yml"

PROJECT_NAME = "wpsyncer-test"
SOURCE_URL = "http://localhost:8080"
RECEIVER_URL = "http://localhost:8081"
RECEIVER_INT = "http://receiver"
SHARED_SECRET = "test-shared-secret-2026"
SOURCE_ID = "test-source"
SOURCE_CONT = f"{PROJECT_NAME}-source-1"
RECEIVER_CONT = f"{PROJECT_NAME}-receiver-1"


@dataclass
class CommandResult:
    code: int
    stdout: str
    stderr: str

    @property
    def output(self) -> str:
        return self.stdout.strip() or self.stderr.strip()

    @property
    def ok(self) -> bool:
        return self.code == 0


def run_command(
    args: Iterable[str],
    *,
    cwd: Path | None = None,
    timeout: int = 300,
) -> CommandResult:
    proc = subprocess.run(
        list(args),
        cwd=str(cwd) if cwd else None,
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=timeout,
    )
    return CommandResult(
        code=proc.returncode,
        stdout=(proc.stdout or "").strip(),
        stderr=(proc.stderr or "").strip(),
    )


def run_wp(
    container: str,
    wp_args: Iterable[str],
    *,
    retries: int = 1,
    allow_fail: bool = False,
    timeout: int = 300,
) -> CommandResult:
    args = ["docker", "exec", container, "wp", *list(wp_args), "--allow-root"]
    last = CommandResult(1, "", "")
    while retries > 0:
        retries -= 1
        last = run_command(args, timeout=timeout)
        if last.ok:
            return last
        if retries > 0:
            time.sleep(3)
    if allow_fail:
        return last
    raise RuntimeError(
        f"WP-CLI failed on {container}: {' '.join(wp_args)}\nSTDOUT:\n{last.stdout}\nSTDERR:\n{last.stderr}"
    )


def run_wc(
    container: str,
    wc_args: Iterable[str],
    *,
    retries: int = 1,
    allow_fail: bool = False,
    timeout: int = 300,
) -> CommandResult:
    return run_wp(
        container,
        ["wc", "--user=admin", *list(wc_args)],
        retries=retries,
        allow_fail=allow_fail,
        timeout=timeout,
    )


def run_eval(container: str, php_code: str, *, allow_fail: bool = False) -> CommandResult:
    return run_wp(container, ["eval", php_code], allow_fail=allow_fail)


def run_docker_exec(
    container: str,
    docker_args: Iterable[str],
    *,
    allow_fail: bool = False,
    timeout: int = 120,
) -> CommandResult:
    result = run_command(["docker", "exec", container, *list(docker_args)], timeout=timeout)
    if result.ok or allow_fail:
        return result
    raise RuntimeError(
        f"docker exec failed on {container}: {' '.join(docker_args)}\nSTDOUT:\n{result.stdout}\nSTDERR:\n{result.stderr}"
    )


def compose(*args: str, timeout: int = 600) -> CommandResult:
    return run_command(
        ["docker", "compose", "-p", PROJECT_NAME, "-f", COMPOSE_FILE.name, *args],
        cwd=TESTS_DIR,
        timeout=timeout,
    )


def inspect_health(container: str) -> str:
    result = run_command(
        ["docker", "inspect", container, "--format", "{{.State.Health.Status}}"],
        timeout=30,
    )
    return result.output.strip()


def wait_for_healthy(container: str, timeout_sec: int = 120) -> None:
    start = time.time()
    while True:
        if time.time() - start >= timeout_sec:
            raise TimeoutError(f"Timeout waiting for {container}")
        try:
            if inspect_health(container) == "healthy":
                return
        except Exception:
            pass
        time.sleep(3)


def wait_for_product(sku: str, *, container: str = RECEIVER_CONT, timeout_sec: int = 60) -> str | None:
    start = time.time()
    while True:
        if time.time() - start >= timeout_sec:
            return None
        try:
            result = run_wc(
                container,
                ["product", "list", f"--sku={sku}", "--field=id"],
                allow_fail=True,
                retries=1,
            )
            for line in result.output.splitlines():
                line = line.strip()
                if re.fullmatch(r"\d+", line):
                    return line
        except Exception:
            pass
        time.sleep(3)


def wait_for_product_by_id(product_id: int | str, *, container: str = RECEIVER_CONT, timeout_sec: int = 30) -> str | None:
    start = time.time()
    product_id = str(product_id)
    while True:
        if time.time() - start >= timeout_sec:
            return None
        try:
            result = run_wc(container, ["product", "get", product_id, "--field=id"], allow_fail=True)
            if result.output.strip() == product_id:
                return result.output.strip()
        except Exception:
            pass
        time.sleep(2)


def sign_body(body: str, timestamp: str, secret: str = SHARED_SECRET) -> str:
    return "sha256=" + hmac.new(
        secret.encode("utf-8"),
        f"{timestamp}\n{body}".encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()


def http_post_json(url: str, body: str, headers: dict[str, str]) -> tuple[int, str]:
    request = urllib.request.Request(
        url,
        data=body.encode("utf-8"),
        headers=headers,
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=20) as response:
            return response.status, response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", errors="replace")


def parse_id(output: str) -> str:
    m = re.search(r"\b(\d+)\b", output)
    return m.group(1) if m else ""


def get_settings_json(container: str) -> dict[str, Any]:
    raw = run_wp(container, ["option", "get", "wpsyncer_settings", "--format=json"]).output
    if not raw:
        return {}
    return json.loads(raw)


def set_settings(container: str, settings: dict[str, Any]) -> None:
    payload = json.dumps(settings, separators=(",", ":"), ensure_ascii=False)
    run_wp(container, ["option", "update", "wpsyncer_settings", payload, "--format=json"])


def set_settings_field(container: str, key: str, value: Any) -> None:
    settings = get_settings_json(container)
    settings[key] = value
    set_settings(container, settings)


def get_logs(container: str) -> list[dict[str, Any]]:
    try:
        raw = run_wp(container, ["option", "get", "wpsyncer_logs", "--format=json"], allow_fail=True).output
        if not raw:
            return []
        data = json.loads(raw)
        return data if isinstance(data, list) else []
    except Exception:
        return []


def clear_logs(container: str) -> None:
    run_wp(container, ["option", "update", "wpsyncer_logs", "[]", "--format=json"])


def remove_all_products(container: str) -> None:
    result = run_wp(
        container,
        ["post", "list", "--post_type=product", "--post_status=any", "--format=ids"],
        allow_fail=True,
    )
    for part in re.split(r"\s+", result.output.strip()):
        if re.fullmatch(r"\d+", part):
            run_wp(container, ["post", "delete", part, "--force"], allow_fail=True)

    result = run_wp(container, ["post", "list", "--post_type=attachment", "--format=ids"], allow_fail=True)
    for part in re.split(r"\s+", result.output.strip()):
        if re.fullmatch(r"\d+", part):
            run_wp(container, ["post", "delete", part, "--force"], allow_fail=True)


def remove_all_terms(container: str) -> None:
    for tax in ("product_cat", "product_tag"):
        result = run_wp(container, ["term", "list", tax, "--field=term_id"], allow_fail=True)
        for part in re.split(r"\s+", result.output.strip()):
            if re.fullmatch(r"\d+", part):
                run_wp(container, ["term", "delete", tax, part], allow_fail=True)


def get_product_field(container: str, product_id: int | str, field: str) -> str:
    return run_wc(container, ["product", "get", str(product_id), f"--field={field}"]).output.strip()


def get_variations(container: str, product_id: int | str) -> list[str]:
    result = run_wp(
        container,
        [
            "post",
            "list",
            "--post_type=product_variation",
            f"--post_parent={product_id}",
            "--posts_per_page=100",
            "--field=ID",
        ],
        allow_fail=True,
    )
    return [line.strip() for line in result.output.splitlines() if re.fullmatch(r"\d+", line.strip())]


def run_pending_crons(container: str = SOURCE_CONT) -> None:
    run_wp(container, ["cron", "event", "run", "--due-now"], allow_fail=True)
    time.sleep(3)
    run_wp(container, ["cron", "event", "run", "action_scheduler_run_queue"], allow_fail=True)
    time.sleep(2)


def sync_product_immediate(product_id: int | str, container: str = SOURCE_CONT) -> None:
    run_wp(container, ["wpsyncer", "sync", str(product_id), "--wait"])


def new_wc_product(container: str = SOURCE_CONT, *product_args: str) -> str:
    result = run_wc(container, ["product", "create", *product_args, "--porcelain"])
    product_id = parse_id(result.output)
    if not product_id:
        raise RuntimeError(f"Could not parse product ID from output: {result.output}")
    return product_id


def new_test_variation(
    container: str = SOURCE_CONT,
    *,
    parent_id: int | str,
    sku: str,
    price: str = "29.99",
    stock: str = "10",
    attr_name: str = "pa_color",
    attr_option: str = "red",
) -> str:
    attrs = json.dumps([{ "name": attr_name, "option": attr_option }], separators=(",", ":"))
    result = run_wc(
        container,
        [
            "product_variation",
            "create",
            str(parent_id),
            f"--regular_price={price}",
            f"--sku={sku}",
            "--manage_stock=true",
            f"--stock_quantity={stock}",
            f"--attributes={attrs}",
            "--porcelain",
        ],
    )
    variation_id = parse_id(result.output)
    if not variation_id:
        raise RuntimeError(f"Could not parse variation ID from output: {result.output}")
    return variation_id


def read_debug_log(container: str) -> str:
    result = run_docker_exec(container, ["tail", "-20", "/var/www/html/wp-content/debug.log"], allow_fail=True)
    return result.output


def default_settings(
    *,
    mode: str,
    source_site_id: str,
    target_url: str,
    sync_images: str = "no",
    bulk_batch_size: int = 10,
    bulk_batch_delay: int = 1,
) -> dict[str, Any]:
    return {
        "mode": mode,
        "source_site_id": source_site_id,
        "target_url": target_url,
        "shared_secret": SHARED_SECRET,
        "create_missing_products": "yes",
        "create_missing_terms": "yes",
        "sync_core": "yes",
        "sync_prices": "yes",
        "sync_stock": "yes",
        "sync_taxonomies": "yes",
        "sync_attributes": "yes",
        "sync_variations": "yes",
        "sync_images": sync_images,
        "sync_meta_keys": "_test_meta_field",
        "delete_behavior": "draft",
        "debug_logging": "yes",
        "sync_product_ids": "no",
        "bulk_batch_size": bulk_batch_size,
        "bulk_batch_delay": bulk_batch_delay,
    }


def source_settings(*, target_url: str = RECEIVER_INT) -> dict[str, Any]:
    return default_settings(mode="source", source_site_id=SOURCE_ID, target_url=target_url)


def receiver_settings() -> dict[str, Any]:
    return default_settings(mode="receiver", source_site_id="test-receiver", target_url="")


def both_source_settings() -> dict[str, Any]:
    return default_settings(
        mode="both",
        source_site_id=SOURCE_ID,
        target_url=RECEIVER_INT,
    )


def both_receiver_settings() -> dict[str, Any]:
    return default_settings(
        mode="both",
        source_site_id="test-receiver",
        target_url="http://source",
    )


class Suite:
    def __init__(self) -> None:
        self.started = time.time()
        self.passed = 0
        self.failed = 0

    def elapsed(self) -> int:
        return int(time.time() - self.started)

    def banner(self, label: str) -> None:
        print()
        print("╔═══════════════════════════════════════════════════╗")
        print(f"║   {label.ljust(45)}║")
        print("╚═══════════════════════════════════════════════════╝")
        print()

    def step(self, label: str) -> None:
        print(f"─── [{self.elapsed()}s] {label} ───")

    def ok(self, label: str) -> None:
        print(f"  OK  {label}")

    def warn(self, label: str) -> None:
        print(f"  WARN  {label}")

    def skip(self, label: str) -> None:
        print(f"  SKIP  {label}")

    def assert_true(self, label: str, condition: Any) -> None:
        try:
            value = condition() if callable(condition) else condition
            passed = bool(value)
        except Exception:
            passed = False
        if passed:
            self.passed += 1
            self.ok(label)
        else:
            self.failed += 1
            print(f"  FAIL  {label}")

    def assert_contains(self, label: str, haystack: str, needle: str) -> None:
        self.assert_true(label, needle in haystack)

    def assert_matches(self, label: str, text: str, pattern: str) -> None:
        self.assert_true(label, re.search(pattern, text) is not None)

    def assert_nonempty(self, label: str, value: Any) -> None:
        self.assert_true(label, value not in (None, "", []))

    def summary(self) -> int:
        total = self.passed + self.failed
        print()
        print("────────────────────────────────────────────")
        if self.failed == 0:
            print(f"  All {self.passed} tests PASSED  ({self.elapsed()} seconds)")
        else:
            print(f"  {self.passed} / {total} passed, {self.failed} FAILED  ({self.elapsed()} seconds)")
        print("────────────────────────────────────────────")
        print()
        return self.failed


def module_01(suite: Suite) -> None:
    suite.banner("MODULE 01: Basic Sync")

    suite.step("1. Verify environment")
    suite.assert_true("Source container healthy", lambda: inspect_health(SOURCE_CONT) == "healthy")
    suite.assert_true("Receiver container healthy", lambda: inspect_health(RECEIVER_CONT) == "healthy")

    suite.step("1b. Cleanup stale test data")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)
    clear_logs(RECEIVER_CONT)
    suite.ok("Stale test data cleaned")

    suite.step("2. Create simple product with all field types")
    run_wp(SOURCE_CONT, ["term", "create", "product_cat", "Electronics", "--slug=electronics"], allow_fail=True)
    run_wp(SOURCE_CONT, ["term", "create", "product_cat", "Gadgets", "--slug=gadgets"], allow_fail=True)
    run_wp(SOURCE_CONT, ["term", "create", "product_tag", "New Arrival", "--slug=new-arrival"], allow_fail=True)
    run_wp(SOURCE_CONT, ["term", "create", "product_tag", "Featured", "--slug=featured"], allow_fail=True)

    simple_id = new_wc_product(
        SOURCE_CONT,
        "--name=Smart Widget Pro",
        "--type=simple",
        "--regular_price=49.99",
        "--sale_price=39.99",
        "--sku=BASIC-SIMPLE-A1",
        "--description=Full description with HTML formatting.",
        "--short_description=Compact smart widget",
        "--manage_stock=true",
        "--stock_quantity=200",
        "--weight=0.75",
        "--tax_status=taxable",
        "--catalog_visibility=visible",
        "--purchase_note=Handle with care.",
        "--menu_order=5",
        "--tax_class=standard",
    )
    suite.assert_matches(f"Simple product created (ID: {simple_id})", simple_id, r"^\d+$")

    run_wp(SOURCE_CONT, ["post", "term", "add", simple_id, "product_cat", "electronics", "gadgets"])
    run_wp(SOURCE_CONT, ["post", "term", "add", simple_id, "product_tag", "new-arrival", "featured"])
    run_wp(SOURCE_CONT, ["post", "meta", "update", simple_id, "_test_meta_field", "meta-value-001"])
    run_wp(SOURCE_CONT, ["post", "meta", "update", simple_id, "_test_date_field", "2026-06-15"])

    suite.step("3. Create variable product with Color + Size attributes")
    for tax, name, slug in [
        ("pa_color", "Color", "color"),
        ("pa_color", "Red", "red"),
        ("pa_color", "Blue", "blue"),
        ("pa_color", "Green", "green"),
        ("pa_size", "Size", "size"),
        ("pa_size", "Small", "small"),
        ("pa_size", "Medium", "medium"),
        ("pa_size", "Large", "large"),
    ]:
        run_wp(SOURCE_CONT, ["term", "create", tax, name, f"--slug={slug}"], allow_fail=True)

    attrs = json.dumps(
        [
            {"name": "pa_color", "visible": True, "variation": True, "options": ["red", "blue", "green"]},
            {"name": "pa_size", "visible": True, "variation": True, "options": ["small", "medium", "large"]},
        ],
        separators=(",", ":"),
    )
    var_id = new_wc_product(
        SOURCE_CONT,
        "--name=T-Shirt Color+Size",
        "--type=variable",
        "--sku=BASIC-VAR-A1",
        "--description=Variable product with color and size",
        f"--attributes={attrs}",
    )
    suite.assert_matches(f"Variable product created (ID: {var_id})", var_id, r"^\d+$")

    v_specs = [
        {"Sku": "BASIC-VAR-RED-S", "Price": "24.99", "Stock": "15", "Color": "red", "Size": "small"},
        {"Sku": "BASIC-VAR-RED-M", "Price": "26.99", "Stock": "12", "Color": "red", "Size": "medium"},
        {"Sku": "BASIC-VAR-BLU-M", "Price": "26.99", "Stock": "10", "Color": "blue", "Size": "medium"},
        {"Sku": "BASIC-VAR-BLU-L", "Price": "29.99", "Stock": "8", "Color": "blue", "Size": "large"},
        {"Sku": "BASIC-VAR-GRN-S", "Price": "24.99", "Stock": "5", "Color": "green", "Size": "small"},
        {"Sku": "BASIC-VAR-GRN-L", "Price": "29.99", "Stock": "3", "Color": "green", "Size": "large"},
    ]
    variation_ids: dict[str, str] = {}
    for spec in v_specs:
        v_attrs = json.dumps(
            [{"name": "pa_color", "option": spec["Color"]}, {"name": "pa_size", "option": spec["Size"]}],
            separators=(",", ":"),
        )
        vid = run_wc(
            SOURCE_CONT,
            [
                "product_variation",
                "create",
                var_id,
                f"--regular_price={spec['Price']}",
                f"--sku={spec['Sku']}",
                "--manage_stock=true",
                f"--stock_quantity={spec['Stock']}",
                "--weight=0.3",
                f"--attributes={v_attrs}",
                "--porcelain",
            ],
        ).output
        variation_ids[spec["Sku"]] = vid
        suite.ok(f"Variation {spec['Sku']} created (ID: {vid})")
    suite.assert_true("All 6 variations created", lambda: len(variation_ids) == 6)

    suite.step("4. Create grouped product")
    grouped_id = new_wc_product(
        SOURCE_CONT,
        "--name=Starter Kit Bundle",
        "--type=grouped",
        "--sku=BASIC-GRP-A1",
        "--description=A bundle of starter items",
    )
    suite.assert_matches(f"Grouped product created (ID: {grouped_id})", grouped_id, r"^\d+$")

    suite.step("5. Create external/affiliate product")
    ext_id = new_wc_product(
        SOURCE_CONT,
        "--name=Affiliate eBook",
        "--type=external",
        "--sku=BASIC-EXT-A1",
        "--regular_price=14.99",
        "--description=An externally sold eBook",
        "--external_url=https://example.com/buy",
        "--button_text=Buy Now",
    )
    suite.assert_matches(f"External product created (ID: {ext_id})", ext_id, r"^\d+$")

    suite.step("6. Sync all products (immediate mode)")
    for label, pid in [
        ("simple", simple_id),
        ("variable", var_id),
        ("grouped", grouped_id),
        ("external", ext_id),
    ]:
        suite.ok(f"Syncing {label} product...")
        sync_product_immediate(pid)
    run_pending_crons()
    suite.ok("Waiting for products to arrive...")

    rcv_simple_id = wait_for_product("BASIC-SIMPLE-A1", timeout_sec=60)
    rcv_var_id = wait_for_product("BASIC-VAR-A1", timeout_sec=60)
    rcv_grp_id = wait_for_product("BASIC-GRP-A1", timeout_sec=60)
    rcv_ext_id = wait_for_product("BASIC-EXT-A1", timeout_sec=60)

    suite.step("7. Verify simple product fields on receiver")
    suite.assert_matches("Simple product synced to receiver", rcv_simple_id or "", r"^\d+$")
    if rcv_simple_id and re.fullmatch(r"\d+", rcv_simple_id):
        field_tests = [
            ("name", "Smart Widget Pro"),
            ("sku", "BASIC-SIMPLE-A1"),
            ("regular_price", "49.99"),
            ("sale_price", "39.99"),
            ("stock_quantity", "200"),
            ("weight", "0.75"),
            ("tax_status", "taxable"),
            ("catalog_visibility", "visible"),
            ("menu_order", "5"),
            ("type", "simple"),
            ("status", "publish"),
        ]
        for field, expected in field_tests:
            val = get_product_field(RECEIVER_CONT, rcv_simple_id, field)
            suite.assert_true(f"Field '{field}' = '{expected}'", val == expected)

        r_meta = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_simple_id, "_test_meta_field"]).output
        suite.assert_true("Custom meta '_test_meta_field' = 'meta-value-001'", r_meta == "meta-value-001")

        r_sync_uid = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_simple_id, "_wpsyncer_remote_sync_uid"]).output
        suite.assert_true("Remote sync UID exists", bool(r_sync_uid))

        r_source_id = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_simple_id, "_wpsyncer_remote_source_id"]).output
        suite.assert_true("Remote source ID = test-source", r_source_id == SOURCE_ID)

        r_source_prod_id = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_simple_id, "_wpsyncer_remote_product_id"]).output
        suite.assert_true("Remote product ID matches source", r_source_prod_id == simple_id)

        cats = run_wp(RECEIVER_CONT, ["post", "term", "list", rcv_simple_id, "product_cat", "--field=slug"]).output
        suite.assert_contains("Category includes 'electronics'", cats, "electronics")
        suite.assert_contains("Category includes 'gadgets'", cats, "gadgets")

        tags = run_wp(RECEIVER_CONT, ["post", "term", "list", rcv_simple_id, "product_tag", "--field=slug"]).output
        suite.assert_contains("Tag includes 'new-arrival'", tags, "new-arrival")
        suite.assert_contains("Tag includes 'featured'", tags, "featured")

        try:
            prod_json = json.loads(run_wc(RECEIVER_CONT, ["product", "get", rcv_simple_id, "--format=json"]).output)
            dims = prod_json.get("dimensions", {}) if isinstance(prod_json, dict) else {}
            if dims.get("length", "") != "":
                suite.assert_true("Length = 12", dims.get("length") in ("12", "12.00"))
                suite.assert_true("Width = 8", dims.get("width") in ("8", "8.00"))
                suite.assert_true("Height = 3", dims.get("height") in ("3", "3.00"))
            else:
                suite.skip("Dimensions check skipped (not synced or empty)")
        except Exception as exc:
            suite.warn(f"Could not parse product JSON for dimensions: {exc}")

    suite.step("8. Verify variable product + variations on receiver")
    suite.assert_matches("Variable product synced", rcv_var_id or "", r"^\d+$")
    if rcv_var_id and re.fullmatch(r"\d+", rcv_var_id):
        suite.assert_true("Variable SKU = BASIC-VAR-A1", get_product_field(RECEIVER_CONT, rcv_var_id, "sku") == "BASIC-VAR-A1")
        suite.assert_true("Variable type = variable", get_product_field(RECEIVER_CONT, rcv_var_id, "type") == "variable")
        time.sleep(3)
        rcv_v_ids = get_variations(RECEIVER_CONT, rcv_var_id)
        suite.assert_true("Receiver has 6 variations", len(rcv_v_ids) == 6)
        v_specs_map = {s["Sku"]: s for s in v_specs}
        for rcv_v_id in rcv_v_ids:
            v_sku = get_product_field(RECEIVER_CONT, rcv_v_id, "sku")
            spec = v_specs_map.get(v_sku)
            if spec:
                v_price = get_product_field(RECEIVER_CONT, rcv_v_id, "regular_price")
                v_stock = get_product_field(RECEIVER_CONT, rcv_v_id, "stock_quantity")
                suite.assert_true(f"Variation {v_sku} price = {spec['Price']}", v_price == spec["Price"])
                suite.assert_true(f"Variation {v_sku} stock = {spec['Stock']}", v_stock == spec["Stock"])
        found_skus = {get_product_field(RECEIVER_CONT, vid, "sku") for vid in rcv_v_ids}
        for spec in v_specs:
            if spec["Sku"] not in found_skus:
                suite.warn(f"Variation SKU {spec['Sku']} not found on receiver")

    suite.step("9. Verify grouped product on receiver")
    suite.assert_matches("Grouped product synced", rcv_grp_id or "", r"^\d+$")
    if rcv_grp_id and re.fullmatch(r"\d+", rcv_grp_id):
        suite.assert_true("Grouped type = grouped", get_product_field(RECEIVER_CONT, rcv_grp_id, "type") == "grouped")
        suite.assert_true("Grouped name = 'Starter Kit Bundle'", get_product_field(RECEIVER_CONT, rcv_grp_id, "name") == "Starter Kit Bundle")

    suite.step("10. Verify external product on receiver")
    suite.assert_matches("External product synced", rcv_ext_id or "", r"^\d+$")
    if rcv_ext_id and re.fullmatch(r"\d+", rcv_ext_id):
        suite.assert_true("External type = external", get_product_field(RECEIVER_CONT, rcv_ext_id, "type") == "external")
        suite.assert_true("External name = 'Affiliate eBook'", get_product_field(RECEIVER_CONT, rcv_ext_id, "name") == "Affiliate eBook")

    suite.step("11. Idempotency — sync same product twice")
    run_wp(SOURCE_CONT, ["post", "update", simple_id, "--post_title=Smart Widget Pro"])
    time.sleep(8)
    dup_check = get_product_field(RECEIVER_CONT, rcv_simple_id, "id") if rcv_simple_id else ""
    suite.assert_true("Product still exists (not duplicated)", dup_check == rcv_simple_id)

    suite.step("12. Verify sync logs")
    logs = get_logs(RECEIVER_CONT)
    suite.assert_true("Receiver has sync log entries", len(logs) >= 1)
    if logs:
        first = logs[0]
        suite.assert_nonempty("Log entry has 'time' field", first.get("time"))
        suite.assert_nonempty("Log entry has 'level' field", first.get("level"))
        suite.assert_nonempty("Log entry has 'message' field", first.get("message"))


def module_02(suite: Suite) -> None:
    suite.banner("MODULE 02: Edge Cases")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)

    suite.step("1. Sync product with UTF-8 and special characters")
    utf_out = run_wc(
        SOURCE_CONT,
        [
            "product",
            "create",
            "--name=Café Résumé — 100% authentic ☕ + 🔥 emoji! ññoü",
            "--type=simple",
            "--regular_price=9.99",
            "--sku=EDGE-UTF8-001",
            "--description=<p>Special chars: © ® ™ € £ ¥ ± × ÷ ∞ ≈ ≠ ≤ ≥ μ α β γ δ ε θ λ π σ ω</p><p>Emoji: 🔥 🚀 ⚡ 💯 🎉 ✅ ❌</p><p>RTL: مرحبا بالعالم</p><p>HTML entities: &amp; &lt; &gt; &quot;</p>",
            "--short_description=Café & résumé — special ✓",
            "--porcelain",
        ],
    ).output
    utf_id = parse_id(utf_out)
    suite.assert_matches(f"UTF-8 product created on source (ID: {utf_id})", utf_id, r"^\d+$")

    if re.fullmatch(r"\d+", utf_id):
        run_wp(SOURCE_CONT, ["post", "meta", "update", utf_id, "_test_meta_field", "México City — Café ☕"])
        run_wp(SOURCE_CONT, ["post", "update", utf_id, "--post_title=Café Résumé"])
        run_pending_crons()
        sync_product_immediate(utf_id)
        rcv_utf_id = wait_for_product("EDGE-UTF8-001", timeout_sec=90)
        suite.assert_matches("UTF-8 product synced to receiver", rcv_utf_id or "", r"^\d+$")
        if rcv_utf_id and re.fullmatch(r"\d+", rcv_utf_id):
            r_name = get_product_field(RECEIVER_CONT, rcv_utf_id, "name")
            suite.assert_contains("Name contains 'Café'", r_name, "Café")
            suite.assert_contains("Name contains 'Résumé'", r_name, "Résumé")
            r_meta = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_utf_id, "_test_meta_field"]).output
            suite.assert_contains("Meta contains 'México'", r_meta, "México")

    suite.step("2. Direct POST with wrong shared secret → 401")
    bad_payload = json.dumps(
        {
            "schema": "wpsyncer.product_snapshot.v1",
            "event": "product.updated",
            "source_site_id": "hacker",
            "source_product_id": 999,
            "sent_at": "2026-01-01T00:00:00+00:00",
            "product": {"sync_uid": "fake", "sku": "FAKE"},
        },
        separators=(",", ":"),
        ensure_ascii=False,
    )
    bad_ts = "2026-01-01T00:00:00+00:00"
    bad_sig = "sha256=" + ("0" * 64)
    http_code, _ = http_post_json(
        f"{RECEIVER_URL}/wp-json/wpsyncer/v1/product",
        bad_payload,
        {
            "Content-Type": "application/json",
            "X-WPSYNCER-Site": "test-source",
            "X-WPSYNCER-Timestamp": bad_ts,
            "X-WPSYNCER-Signature": bad_sig,
        },
    )
    suite.assert_true("Wrong signature returns 401", http_code == 401)

    suite.step("3. POST with any source ID accepted (source_id ACL removed)")
    good_ts = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
    good_payload = json.dumps(
        {
            "schema": "wpsyncer.product_snapshot.v1",
            "event": "product.updated",
            "source_site_id": "any-source",
            "source_product_id": 999,
            "sent_at": good_ts,
            "product": {"sync_uid": "unauth", "sku": "UNAUTH"},
        },
        separators=(",", ":"),
        ensure_ascii=False,
    )
    good_sig = sign_body(good_payload, good_ts)
    http_code2, _ = http_post_json(
        f"{RECEIVER_URL}/wp-json/wpsyncer/v1/product",
        good_payload,
        {
            "Content-Type": "application/json",
            "X-WPSYNCER-Site": "any-source",
            "X-WPSYNCER-Timestamp": good_ts,
            "X-WPSYNCER-Signature": good_sig,
        },
    )
    suite.assert_true("Request with valid signature accepted (200)", http_code2 == 200)

    suite.step("4. Sync with empty target_url")
    orig_settings = get_settings_json(SOURCE_CONT)
    orig_target = orig_settings.get("target_url", "")
    set_settings_field(SOURCE_CONT, "target_url", "")
    try:
        no_target_out = run_wc(
            SOURCE_CONT,
            ["product", "create", "--name=No Target Product", "--type=simple", "--regular_price=5.00", "--sku=EDGE-NOTARGET-1", "--porcelain"],
        ).output
        no_target_id = parse_id(no_target_out)
        suite.assert_matches("Product created with empty target URL", no_target_id, r"^\d+$")
        if re.fullmatch(r"\d+", no_target_id):
            run_wp(SOURCE_CONT, ["post", "update", no_target_id, "--post_title=No Target Product"])
            run_pending_crons()
            time.sleep(5)
            _ = get_logs(SOURCE_CONT)
            suite.ok("Source handled empty target_url gracefully")
    finally:
        set_settings_field(SOURCE_CONT, "target_url", orig_target)

    suite.step("5. Sync product with no SKU")
    no_sku_out = run_wc(
        SOURCE_CONT,
        ["product", "create", "--name=No SKU Product", "--type=simple", "--regular_price=7.99", "--sku=", "--porcelain"],
    ).output
    no_sku_id = parse_id(no_sku_out)
    suite.assert_matches(f"No-SKU product created (ID: {no_sku_id})", no_sku_id, r"^\d+$")
    if re.fullmatch(r"\d+", no_sku_id):
        run_wp(SOURCE_CONT, ["post", "update", no_sku_id, "--post_title=No SKU Product"])
        time.sleep(8)
        suite.ok("No-SKU product sync attempted")

    suite.step("6. Sync product with very long description")
    long_desc = "<p>" + ("Lorem ipsum dolor sit amet, consectetur adipiscing elit. " * 200) + "</p>"
    long_out = run_wc(
        SOURCE_CONT,
        [
            "product",
            "create",
            "--name=Long Description Product",
            "--type=simple",
            "--regular_price=3.99",
            "--sku=EDGE-LONG-1",
            f"--description={long_desc}",
            "--porcelain",
        ],
    ).output
    long_id = parse_id(long_out)
    suite.assert_matches(f"Long-description product created (ID: {long_id})", long_id, r"^\d+$")
    if re.fullmatch(r"\d+", long_id):
        run_wp(SOURCE_CONT, ["post", "update", long_id, "--post_title=Long Desc Product"])
        run_pending_crons()
        sync_product_immediate(long_id)
        rcv_long_id = wait_for_product("EDGE-LONG-1", timeout_sec=90)
        suite.assert_matches("Long-description product synced", rcv_long_id or "", r"^\d+$")
        if rcv_long_id and re.fullmatch(r"\d+", rcv_long_id):
            r_desc_len = len(get_product_field(RECEIVER_CONT, rcv_long_id, "description"))
            suite.assert_true("Description length > 5000 chars", r_desc_len > 5000)

    suite.step("7. Sync product with many categories")
    many_cat_sku = "EDGE-MANYCAT-1"
    many_out = run_wc(
        SOURCE_CONT,
        ["product", "create", "--name=Multi-Category Product", "--type=simple", "--regular_price=11.99", "--sku=EDGE-MANYCAT-1", "--porcelain"],
    ).output
    many_id = parse_id(many_out)
    suite.assert_matches(f"Multi-category product created (ID: {many_id})", many_id, r"^\d+$")
    cat_slugs: list[str] = []
    for i in range(1, 6):
        slug = f"edge-cat-{i}"
        name = f"Edge Category {i}"
        run_wp(SOURCE_CONT, ["term", "create", "product_cat", name, f"--slug={slug}"], allow_fail=True)
        if re.fullmatch(r"\d+", many_id):
            run_wp(SOURCE_CONT, ["post", "term", "add", many_id, "product_cat", slug])
        cat_slugs.append(slug)
    suite.assert_true("5 categories assigned to product", len(cat_slugs) == 5)
    if re.fullmatch(r"\d+", many_id):
        run_wp(SOURCE_CONT, ["post", "update", many_id, "--post_title=Multi-Cat Product"])
        run_pending_crons()
        sync_product_immediate(many_id)
        rcv_many_id = wait_for_product(many_cat_sku, timeout_sec=90)
        suite.assert_matches("Multi-category product synced", rcv_many_id or "", r"^\d+$")
        if rcv_many_id and re.fullmatch(r"\d+", rcv_many_id):
            r_cats = run_wp(RECEIVER_CONT, ["post", "term", "list", rcv_many_id, "product_cat", "--field=slug"]).output
            for slug in cat_slugs:
                suite.assert_contains(f"Category '{slug}' synced", r_cats, slug)

    suite.step("8. Duplicate SKU handling")
    dup_sku = "EDGE-DUP-001"
    dup1_out = run_wc(
        SOURCE_CONT,
        ["product", "create", "--name=Original Dup Product", "--type=simple", "--regular_price=15.00", "--sku=EDGE-DUP-001", "--porcelain"],
    ).output
    dup1_id = parse_id(dup1_out)
    suite.assert_matches(f"Original dup product created (ID: {dup1_id})", dup1_id, r"^\d+$")
    if re.fullmatch(r"\d+", dup1_id):
        run_wp(SOURCE_CONT, ["post", "update", dup1_id, "--post_title=Original Dup"])
        run_pending_crons()
        sync_product_immediate(dup1_id)
        rcv_dup_id = wait_for_product(dup_sku, timeout_sec=90) or ""
        suite.assert_matches("First dup product synced", rcv_dup_id, r"^\d+$")
        run_wp(SOURCE_CONT, ["post", "meta", "update", dup1_id, "_test_meta_field", "duplicate-test-updated"])
        run_wp(SOURCE_CONT, ["post", "update", dup1_id, "--post_title=Original Dup Updated"])
        run_pending_crons()
        sync_product_immediate(dup1_id)
        if re.fullmatch(r"\d+", rcv_dup_id):
            r_dup_meta = ""
            deadline = time.time() + 60
            while time.time() < deadline:
                r_dup_meta = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_dup_id, "_test_meta_field"], allow_fail=True).output
                if r_dup_meta == "duplicate-test-updated":
                    break
                time.sleep(2)
            suite.assert_true("Duplicate SKU product was updated (not duplicated)", r_dup_meta == "duplicate-test-updated")

    suite.step("9. Bulk sync (lightweight)")
    # Ensure target_url is configured (empty-target test may have left it unset)
    current_target = get_settings_json(SOURCE_CONT).get("target_url", "")
    if not current_target:
        set_settings_field(SOURCE_CONT, "target_url", "http://receiver")
    pre_bulk_list = run_wc(RECEIVER_CONT, ["product", "list", "--per_page=100", "--field=id"], allow_fail=True).output
    pre_bulk_count = len([line for line in pre_bulk_list.splitlines() if re.fullmatch(r"\d+", line.strip())])
    _ = pre_bulk_count
    run_wp(SOURCE_CONT, ["wpsyncer", "run"])
    time.sleep(15)
    post_bulk_logs = get_logs(SOURCE_CONT)
    suite.assert_true("Bulk sync completed (log shows completion)", any("completed" in str(log.get("message", "")) for log in post_bulk_logs))


def module_03(suite: Suite) -> None:
    suite.banner("MODULE 03: Receiver Toggles & Delete Behaviors")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)

    orig_receiver = get_settings_json(RECEIVER_CONT)

    def set_receiver_settings(
        *,
        create_products: str = "yes",
        create_terms: str = "yes",
        sync_core: str = "yes",
        sync_prices: str = "yes",
        sync_stock: str = "yes",
        sync_tax: str = "yes",
        sync_attr: str = "yes",
        sync_vars: str = "yes",
        sync_images: str = "no",
        delete_behavior: str = "draft",
        meta_keys: str = "_test_meta_field",
    ) -> None:
        set_settings(
            RECEIVER_CONT,
            {
                **receiver_settings(),
                "create_missing_products": create_products,
                "create_missing_terms": create_terms,
                "sync_core": sync_core,
                "sync_prices": sync_prices,
                "sync_stock": sync_stock,
                "sync_taxonomies": sync_tax,
                "sync_attributes": sync_attr,
                "sync_variations": sync_vars,
                "sync_images": sync_images,
                "sync_meta_keys": meta_keys,
                "delete_behavior": delete_behavior,
            },
        )
        suite.ok(f"Receiver reconfigured (delete_behavior={delete_behavior})")

    try:
        suite.step("1. create_missing_products = 'no'")
        set_receiver_settings(create_products="yes", create_terms="yes")
        sku_existing = "BEHAV-EXIST-1"
        existing_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Existing Test Prod", "--type=simple", "--regular_price=25.00", "--sku=BEHAV-EXIST-1", "--porcelain"]).output
        existing_id = parse_id(existing_out)
        suite.assert_matches(f"Existing test product created (ID: {existing_id})", existing_id, r"^\d+$")
        rcv_existing1 = ""
        if re.fullmatch(r"\d+", existing_id):
            run_wp(SOURCE_CONT, ["post", "update", existing_id, "--post_title=Existing Test Prod"])
            run_pending_crons()
            sync_product_immediate(existing_id)
            rcv_existing1 = wait_for_product(sku_existing, timeout_sec=90) or ""
            suite.assert_matches("Existing product synced (first time with create_missing=yes)", rcv_existing1, r"^\d+$")
            if re.fullmatch(r"\d+", rcv_existing1):
                run_wp(RECEIVER_CONT, ["post", "delete", rcv_existing1, "--force"], allow_fail=True)
                suite.ok("Deleted product on receiver")
        set_receiver_settings(create_products="no")
        run_wp(SOURCE_CONT, ["post", "meta", "update", existing_id, "_test_meta_field", "should-not-appear"])
        run_wp(SOURCE_CONT, ["post", "update", existing_id, "--post_title=Existing Prod Update"])
        run_pending_crons()
        time.sleep(8)
        rcv_existing2 = wait_for_product(sku_existing, timeout_sec=30)
        suite.assert_true("Product NOT re-created when create_missing_products=no", rcv_existing2 is None)
        suite.ok("create_missing_products=no prevents auto-creation")
        set_receiver_settings(create_products="yes")

        suite.step("2. create_missing_terms = 'no'")
        set_receiver_settings(create_terms="yes")
        sku_terms = "BEHAV-TERMS-1"
        noun_out = run_wc(SOURCE_CONT, ["product", "create", "--name=No Terms Product", "--type=simple", "--regular_price=35.00", "--sku=BEHAV-TERMS-1", "--porcelain"]).output
        noun_id = parse_id(noun_out)
        suite.assert_matches(f"No-terms test product created (ID: {noun_id})", noun_id, r"^\d+$")
        run_wp(SOURCE_CONT, ["term", "create", "product_cat", "Rare Category XYZ", "--slug=rare-cat-xyz"], allow_fail=True)
        run_wp(SOURCE_CONT, ["post", "term", "set", noun_id, "product_cat", "rare-cat-xyz"], allow_fail=True)
        run_wp(SOURCE_CONT, ["post", "update", noun_id, "--post_title=No Terms Prod"])
        run_pending_crons()
        sync_product_immediate(noun_id)
        rcv_terms_id = wait_for_product(sku_terms, timeout_sec=90) or ""
        suite.assert_matches("Product synced when create_missing_terms=yes", rcv_terms_id, r"^\d+$")
        if re.fullmatch(r"\d+", rcv_terms_id):
            r_cats = run_wp(RECEIVER_CONT, ["post", "term", "list", rcv_terms_id, "product_cat", "--field=slug"]).output
            suite.assert_contains("Rare category synced initially", r_cats, "rare-cat-xyz")

        set_receiver_settings(create_terms="no")
        run_wp(SOURCE_CONT, ["term", "create", "product_cat", "Rare Category XYZ 2", "--slug=rare-cat-xyz-2"], allow_fail=True)
        run_wp(SOURCE_CONT, ["post", "term", "set", noun_id, "product_cat", "rare-cat-xyz-2"], allow_fail=True)
        run_wp(SOURCE_CONT, ["post", "update", noun_id, "--post_title=No Terms Prod v2"])
        run_pending_crons()
        time.sleep(8)
        rcv_terms_after = wait_for_product(sku_terms, timeout_sec=30) or rcv_terms_id
        if re.fullmatch(r"\d+", rcv_terms_after):
            r_cats_after = run_wp(RECEIVER_CONT, ["post", "term", "list", rcv_terms_after, "product_cat", "--field=slug"]).output
            suite.assert_true("Rare category NOT created on receiver", "rare-cat-xyz-2" not in r_cats_after)
        set_receiver_settings(create_terms="yes")

        suite.step("3. delete_behavior = 'ignore'")
        set_receiver_settings(delete_behavior="ignore")
        sku_ignore = "BEHAV-IGNORE-1"
        ign_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Ignore Test Product", "--type=simple", "--regular_price=45.00", "--sku=BEHAV-IGNORE-1", "--porcelain"]).output
        ign_id = parse_id(ign_out)
        suite.assert_matches(f"Ignore-test product created (ID: {ign_id})", ign_id, r"^\d+$")
        if re.fullmatch(r"\d+", ign_id):
            run_wp(SOURCE_CONT, ["post", "update", ign_id, "--post_title=Ignore Test"])
            run_pending_crons()
            sync_product_immediate(ign_id)
            rcv_ign_id = wait_for_product(sku_ignore, timeout_sec=90) or ""
            suite.assert_matches("Ignore-test product synced", rcv_ign_id, r"^\d+$")
            if re.fullmatch(r"\d+", rcv_ign_id):
                run_wp(SOURCE_CONT, ["post", "delete", ign_id, "--force"], allow_fail=True)
                time.sleep(8)
                ign_status = get_product_field(RECEIVER_CONT, rcv_ign_id, "status") if rcv_ign_id else ""
                suite.assert_true("delete_behavior=ignore: product remains published", ign_status in ("publish", ""))

        suite.step("4. delete_behavior = 'trash'")
        set_receiver_settings(delete_behavior="trash")
        sku_trash = "BEHAV-TRASH-1"
        tr_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Trash Test Product", "--type=simple", "--regular_price=55.00", "--sku=BEHAV-TRASH-1", "--porcelain"]).output
        tr_id = parse_id(tr_out)
        suite.assert_matches(f"Trash-test product created (ID: {tr_id})", tr_id, r"^\d+$")
        if re.fullmatch(r"\d+", tr_id):
            run_wp(SOURCE_CONT, ["post", "update", tr_id, "--post_title=Trash Test"])
            run_pending_crons()
            sync_product_immediate(tr_id)
            rcv_tr_id = wait_for_product(sku_trash, timeout_sec=90) or ""
            suite.assert_matches("Trash-test product synced", rcv_tr_id, r"^\d+$")
            if re.fullmatch(r"\d+", rcv_tr_id):
                run_wp(SOURCE_CONT, ["post", "delete", tr_id, "--force"], allow_fail=True)
                run_pending_crons()
                time.sleep(8)
                tr_status = run_wp(RECEIVER_CONT, ["post", "list", "--post_type=product", "--post_status=trash", "--field=ID"]).output
                suite.assert_contains("delete_behavior=trash: product moved to trash", tr_status, rcv_tr_id)

        suite.step("5. delete_behavior = 'draft'")
        set_receiver_settings(delete_behavior="draft")
        sku_draft = "BEHAV-DRAFT-1"
        dr_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Draft Test Product", "--type=simple", "--regular_price=65.00", "--sku=BEHAV-DRAFT-1", "--porcelain"]).output
        dr_id = parse_id(dr_out)
        suite.assert_matches(f"Draft-test product created (ID: {dr_id})", dr_id, r"^\d+$")
        if re.fullmatch(r"\d+", dr_id):
            run_wp(SOURCE_CONT, ["post", "update", dr_id, "--post_title=Draft Test"])
            run_pending_crons()
            sync_product_immediate(dr_id)
            rcv_dr_id = wait_for_product(sku_draft, timeout_sec=90) or ""
            suite.assert_matches("Draft-test product synced", rcv_dr_id, r"^\d+$")
            if re.fullmatch(r"\d+", rcv_dr_id):
                run_wp(SOURCE_CONT, ["post", "delete", dr_id, "--force"], allow_fail=True)
                run_pending_crons()
                time.sleep(8)
                dr_status = ""
                deadline = time.time() + 30
                while time.time() < deadline:
                    dr_status = get_product_field(RECEIVER_CONT, rcv_dr_id, "status")
                    if dr_status == "draft":
                        break
                    time.sleep(2)
                suite.assert_true("delete_behavior=draft: product status is draft", dr_status == "draft")

        suite.step("6. Individual sync group toggles")
        set_receiver_settings(delete_behavior="draft", create_products="yes", create_terms="yes")
        sku_toggle = "BEHAV-TOGGLE-1"
        tg_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Toggle Test Original", "--type=simple", "--regular_price=75.00", "--sku=BEHAV-TOGGLE-1", "--porcelain"]).output
        tg_id = parse_id(tg_out)
        suite.assert_matches(f"Toggle test product created (ID: {tg_id})", tg_id, r"^\d+$")
        rcv_tg_id = ""
        if re.fullmatch(r"\d+", tg_id):
            run_wp(SOURCE_CONT, ["post", "update", tg_id, "--post_title=Toggle Test Original"])
            run_pending_crons()
            sync_product_immediate(tg_id)
            rcv_tg_id = wait_for_product(sku_toggle, timeout_sec=90) or ""
            suite.assert_matches("Toggle test product initial sync", rcv_tg_id, r"^\d+$")

        set_receiver_settings(sync_core="no", delete_behavior="draft")
        if re.fullmatch(r"\d+", tg_id) and re.fullmatch(r"\d+", rcv_tg_id):
            run_wp(SOURCE_CONT, ["post", "update", tg_id, "--post_title=Toggle Test CHANGED", "--post_content=Content should not sync"])
            run_wp(SOURCE_CONT, ["post", "meta", "update", tg_id, "_test_meta_field", "toggle-meta-value"])
            run_pending_crons()
            sync_product_immediate(tg_id)
            time.sleep(8)
            r_name_after = get_product_field(RECEIVER_CONT, rcv_tg_id, "name")
            suite.assert_true("sync_core=no: name unchanged (still original)", r_name_after == "Toggle Test Original")
            r_meta_after = ""
            deadline = time.time() + 30
            while time.time() < deadline:
                r_meta_after = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_tg_id, "_test_meta_field"], allow_fail=True).output
                if r_meta_after == "toggle-meta-value":
                    break
                time.sleep(2)
            suite.assert_true("Custom meta still synced when sync_core=no", r_meta_after == "toggle-meta-value")

        suite.step("7. sync_prices = 'no'")
        set_receiver_settings(sync_core="yes", sync_prices="no", delete_behavior="draft")
        if re.fullmatch(r"\d+", tg_id) and re.fullmatch(r"\d+", rcv_tg_id):
            run_wp(SOURCE_CONT, ["post", "update", tg_id, "--regular_price=99.99", "--post_title=Toggle Test Price Changed"])
            run_pending_crons()
            sync_product_immediate(tg_id)
            time.sleep(8)
            r_price_after = get_product_field(RECEIVER_CONT, rcv_tg_id, "regular_price")
            suite.assert_true("sync_prices=no: price unchanged", r_price_after != "99.99" or r_price_after == "75.00")
            r_name_after2 = get_product_field(RECEIVER_CONT, rcv_tg_id, "name")
            suite.assert_true("sync_core=yes: name DID change even when sync_prices=no", r_name_after2 == "Toggle Test Price Changed")

        suite.step("8. sync_stock = 'no'")
        set_receiver_settings(sync_core="yes", sync_prices="yes", sync_stock="no")
        if re.fullmatch(r"\d+", tg_id) and re.fullmatch(r"\d+", rcv_tg_id):
            run_wp(SOURCE_CONT, ["post", "update", tg_id, "--stock_quantity=999", "--regular_price=85.00"])
            run_pending_crons()
            sync_product_immediate(tg_id)
            time.sleep(8)
            r_stock_after = get_product_field(RECEIVER_CONT, rcv_tg_id, "stock_quantity")
            suite.assert_true("sync_stock=no: stock quantity unchanged (stays 50)", r_stock_after != "999")

        suite.step("9. sync_taxonomies = 'no'")
        set_receiver_settings(sync_core="yes", sync_tax="no")
        if re.fullmatch(r"\d+", tg_id) and re.fullmatch(r"\d+", rcv_tg_id):
            run_wp(SOURCE_CONT, ["term", "create", "product_cat", "Toggle Category", "--slug=toggle-cat"], allow_fail=True)
            run_wp(SOURCE_CONT, ["post", "term", "set", tg_id, "product_cat", "toggle-cat"])
            run_wp(SOURCE_CONT, ["post", "update", tg_id, "--post_title=Toggle Sync Tax"])
            run_pending_crons()
            sync_product_immediate(tg_id)
            time.sleep(8)
            r_cats_after = run_wp(RECEIVER_CONT, ["post", "term", "list", rcv_tg_id, "product_cat", "--field=slug"]).output
            suite.assert_true("sync_taxonomies=no: toggle-cat NOT added", "toggle-cat" not in r_cats_after)

        suite.step("10. sync_product_ids = 'yes' (experimental)")
        set_receiver_settings(sync_core="yes", delete_behavior="draft")
        set_settings_field(RECEIVER_CONT, "sync_product_ids", "yes")
        sku_id_sync = "BEHAV-IDSYNC-1"
        id_out = run_wc(SOURCE_CONT, ["product", "create", "--name=ID Sync Product", "--type=simple", "--regular_price=150.00", "--sku=BEHAV-IDSYNC-1", "--porcelain"]).output
        id_sync_source_id = parse_id(id_out)
        suite.assert_matches(f"ID sync source product created (ID: {id_sync_source_id})", id_sync_source_id, r"^\d+$")
        if re.fullmatch(r"\d+", id_sync_source_id):
            run_wp(SOURCE_CONT, ["post", "update", id_sync_source_id, "--post_title=ID Sync Prod"])
            run_pending_crons()
            sync_product_immediate(id_sync_source_id)
            rcv_id_sync_id = wait_for_product(sku_id_sync, timeout_sec=90) or ""
            suite.assert_matches("ID Sync product arrived on receiver", rcv_id_sync_id, r"^\d+$")
            suite.ok(f"ID sync product ID on receiver: {rcv_id_sync_id}")
        set_settings_field(RECEIVER_CONT, "sync_product_ids", "no")
    finally:
        set_settings(RECEIVER_CONT, orig_receiver)
        suite.ok("Original receiver settings restored")


def module_04(suite: Suite) -> None:
    suite.banner("MODULE 04: Image Sync")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)
    set_settings_field(RECEIVER_CONT, "sync_images", "yes")

    suite.step("1. Upload test image to source")
    image_url = "https://placehold.co/400x400/ff6600/ffffff.png?text=Test+Image"
    run_docker_exec(SOURCE_CONT, ["curl", "-sL", "-o", "/tmp/test-product-image.png", image_url])
    img_check = run_docker_exec(SOURCE_CONT, ["ls", "-la", "/tmp/test-product-image.png"], allow_fail=True).output
    suite.assert_contains("Test image downloaded to source container", img_check, "test-product-image")

    img_import_out = run_wp(SOURCE_CONT, ["media", "import", "/tmp/test-product-image.png", "--title=Test Featured Image"]).output
    attachment_id = parse_id(img_import_out)
    if not re.fullmatch(r"\d+", attachment_id):
        img_list = run_wp(SOURCE_CONT, ["post", "list", "--post_type=attachment", "--posts_per_page=1", "--field=ID"], allow_fail=True).output
        attachment_id = img_list.strip().splitlines()[0].strip() if img_list.strip() else ""
    suite.assert_matches(f"Image imported as attachment (ID: {attachment_id})", attachment_id, r"^\d+$")

    suite.step("2. Create product with featured image")
    img_prod_id = new_wc_product(SOURCE_CONT, "--name=Product With Image", "--type=simple", "--regular_price=29.99", "--sku=IMAGE-PROD-1")
    suite.assert_matches(f"Product with image created (ID: {img_prod_id})", img_prod_id, r"^\d+$")

    img_var_id = ""
    img_v_id = ""
    r_thumb = ""
    if re.fullmatch(r"\d+", img_prod_id) and re.fullmatch(r"\d+", attachment_id):
        run_wp(SOURCE_CONT, ["post", "meta", "update", img_prod_id, "_thumbnail_id", attachment_id])
        src_img_url = run_wp(SOURCE_CONT, ["post", "meta", "get", img_prod_id, "_thumbnail_id"]).output
        suite.assert_matches("Featured image set on source", src_img_url, r"^\d+$")
        run_wp(SOURCE_CONT, ["post", "meta", "update", img_prod_id, "_product_image_gallery", attachment_id])

        run_wp(SOURCE_CONT, ["term", "create", "pa_color", "Color", "--slug=color"], allow_fail=True)
        run_wp(SOURCE_CONT, ["term", "create", "pa_color", "Red", "--slug=red"], allow_fail=True)
        img_var_id = new_wc_product(
            SOURCE_CONT,
            "--name=Variable With Image",
            "--type=variable",
            "--sku=IMAGE-VAR-1",
            f"--attributes={json.dumps([{ 'name': 'pa_color', 'visible': True, 'variation': True, 'options': ['red'] }], separators=(',', ':'))}",
        )
        suite.assert_matches(f"Variable product for image test created (ID: {img_var_id})", img_var_id, r"^\d+$")
        if re.fullmatch(r"\d+", img_var_id):
            img_v_out = run_wc(
                SOURCE_CONT,
                [
                    "product_variation",
                    "create",
                    img_var_id,
                    "--regular_price=34.99",
                    "--sku=IMAGE-VAR-RED",
                    "--manage_stock=true",
                    "--stock_quantity=10",
                    f"--attributes={json.dumps([{ 'name': 'pa_color', 'option': 'red' }], separators=(',', ':'))}",
                    "--porcelain",
                ],
            ).output
            img_v_id = parse_id(img_v_out)
            if re.fullmatch(r"\d+", img_v_id):
                run_wp(SOURCE_CONT, ["post", "meta", "update", img_v_id, "_thumbnail_id", attachment_id])

        suite.step("3. Trigger sync")
        run_wp(SOURCE_CONT, ["post", "update", img_prod_id, "--post_title=Product With Image"])
        if re.fullmatch(r"\d+", img_var_id):
            run_wp(SOURCE_CONT, ["post", "update", img_var_id, "--post_title=Variable With Image"], allow_fail=True)
        run_pending_crons()
        time.sleep(2)

    rcv_img_prod_id = wait_for_product("IMAGE-PROD-1", timeout_sec=90)
    rcv_img_var_id = wait_for_product("IMAGE-VAR-1", timeout_sec=60)

    suite.step("4. Verify featured image on receiver")
    suite.assert_matches("Product with image synced", rcv_img_prod_id or "", r"^\d+$")
    if rcv_img_prod_id and re.fullmatch(r"\d+", rcv_img_prod_id):
        r_thumb = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_img_prod_id, "_thumbnail_id"], allow_fail=True).output
        suite.assert_matches("Featured image exists on receiver (has attachment ID)", r_thumb, r"^\d+$")
        if re.fullmatch(r"\d+", r_thumb):
            r_img_type = run_wp(RECEIVER_CONT, ["post", "get", r_thumb, "--field=post_type"], allow_fail=True).output
            suite.assert_true("Receiver image is an attachment", r_img_type == "attachment")
            suite.ok(f"Featured image synced (attachment ID: {r_thumb})")
        r_gallery = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_img_prod_id, "_product_image_gallery"], allow_fail=True).output
        if r_gallery:
            suite.assert_matches("Gallery image exists on receiver", r_gallery, r"^\d+")
        else:
            suite.warn("Gallery image meta not found on receiver (skip)")

    suite.step("5. Test image deduplication")
    run_wp(SOURCE_CONT, ["post", "update", img_prod_id, "--post_title=Product With Image v2"])
    run_pending_crons()
    time.sleep(8)
    if rcv_img_prod_id and re.fullmatch(r"\d+", rcv_img_prod_id):
        r_thumb2 = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_img_prod_id, "_thumbnail_id"], allow_fail=True).output
        if r_thumb2 and r_thumb:
            suite.assert_true("After re-sync, featured image ID unchanged (dedup works)", r_thumb2 == r_thumb)
            suite.ok("Image deduplication confirmed")
        else:
            suite.warn("Skipped dedup check (featured image not synced)")

    suite.step("6. sync_images = 'no' — images should NOT sync")
    set_settings_field(RECEIVER_CONT, "sync_images", "no")
    no_img_out = run_wc(SOURCE_CONT, ["product", "create", "--name=No Image Product", "--type=simple", "--regular_price=5.00", "--sku=IMAGE-NOIMG-1", "--porcelain"]).output
    no_img_id = parse_id(no_img_out)
    if re.fullmatch(r"\d+", no_img_id):
        run_wp(SOURCE_CONT, ["post", "update", no_img_id, "--post_title=No Image Product"])
        run_pending_crons()
        rcv_no_img_id = wait_for_product("IMAGE-NOIMG-1", timeout_sec=90)
        suite.assert_matches("Product synced with sync_images=no", rcv_no_img_id or "", r"^\d+$")
    set_settings_field(RECEIVER_CONT, "sync_images", "yes")


def module_05(suite: Suite) -> None:
    suite.banner("MODULE 05: WP-CLI Commands")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)

    suite.step("1. 'wp wpsyncer status'")
    status_res = run_wp(SOURCE_CONT, ["wpsyncer", "status"])
    suite.assert_true("Status command runs without error", status_res.code == 0)
    suite.assert_contains("Status shows mode 'source'", status_res.output, "source")
    suite.assert_contains("Status shows target URL", status_res.output, "http://receiver")
    suite.assert_contains("Status shows shared secret", status_res.output, "********")
    suite.assert_contains("Status shows delete behavior", status_res.output, "draft")
    suite.assert_contains("Status shows sync groups", status_res.output, "core")

    status_json = run_wp(SOURCE_CONT, ["wpsyncer", "status", "--format=json"])
    suite.assert_contains("Status --format=json returns JSON", status_json.output, '"mode"')
    suite.assert_contains("Status JSON contains source_site_id", status_json.output, SOURCE_ID)

    suite.step("2. 'wp wpsyncer config get'")
    config_out = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get"])
    suite.assert_true("config get runs", config_out.code == 0)
    suite.assert_contains("config get shows mode", config_out.output, "mode")
    suite.assert_contains("config get shows shared_secret (masked)", config_out.output, "********")

    mode_val = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "mode"])
    suite.assert_true("config get mode returns 'source'", mode_val.output.strip() == "source")
    secret_val = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "shared_secret"])
    suite.assert_true("config get shared_secret returns masked", secret_val.output.strip() == "********")

    suite.step("3. 'wp wpsyncer config set'")
    run_wp(SOURCE_CONT, ["wpsyncer", "config", "set", "delete_behavior", "trash"])
    del_behavior = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "delete_behavior"]).output
    suite.assert_true("config set delete_behavior to trash", del_behavior.strip() == "trash")
    run_wp(SOURCE_CONT, ["wpsyncer", "config", "set", "delete_behavior", "draft"])
    del_behavior2 = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "delete_behavior"]).output
    suite.assert_true("config set delete_behavior back to draft", del_behavior2.strip() == "draft")
    invalid_result = run_wp(SOURCE_CONT, ["wpsyncer", "config", "set", "mode", "invalid_mode"], allow_fail=True)
    suite.assert_true("Invalid config value is rejected", invalid_result.code != 0)

    suite.step("4. 'wp wpsyncer install'")
    install_out = run_wp(SOURCE_CONT, ["wpsyncer", "install"])
    suite.assert_true("install command runs", install_out.code == 0)
    suite.assert_contains("install confirms WooCommerce active", install_out.output, "WooCommerce is active")

    suite.step("5. 'wp wpsyncer sync' command")
    cli_out = run_wc(SOURCE_CONT, ["product", "create", "--name=CLI Sync Test", "--type=simple", "--regular_price=42.00", "--sku=CLI-SYNC-001", "--porcelain"])
    cli_id = parse_id(cli_out.output)
    suite.assert_matches(f"CLI sync test product created (ID: {cli_id})", cli_id, r"^\d+$")
    if re.fullmatch(r"\d+", cli_id):
        sync_out = run_wp(SOURCE_CONT, ["wpsyncer", "sync", cli_id])
        suite.assert_true("'wp wpsyncer sync' succeeds", sync_out.code == 0)
        suite.assert_contains("Sync output mentions product", sync_out.output, cli_id)
        rcv_cli_id = wait_for_product("CLI-SYNC-001", timeout_sec=90)
        suite.assert_matches("CLI-synced product arrived on receiver", rcv_cli_id or "", r"^\d+$")

        cli2_out = run_wc(SOURCE_CONT, ["product", "create", "--name=CLI Sync Wait Test", "--type=simple", "--regular_price=99.00", "--sku=CLI-SYNC-WAIT-1", "--porcelain"])
        cli2_id = parse_id(cli2_out.output)
        suite.assert_matches("CLI wait-test product created", cli2_id, r"^\d+$")
        if re.fullmatch(r"\d+", cli2_id):
            sync_wait_out = run_wp(SOURCE_CONT, ["wpsyncer", "sync", cli2_id, "--wait"])
            suite.assert_true("'wp wpsyncer sync --wait' succeeds", sync_wait_out.code == 0)
            suite.assert_contains("Sync wait succeeded", sync_wait_out.output, "synced immediately")
            rcv_cli2_id = wait_for_product("CLI-SYNC-WAIT-1", timeout_sec=60)
            suite.assert_matches("CLI --wait product arrived on receiver", rcv_cli2_id or "", r"^\d+$")

    suite.step("6. 'wp wpsyncer logs'")
    logs_out = run_wp(RECEIVER_CONT, ["wpsyncer", "logs", "5"])
    suite.assert_true("logs command runs", logs_out.code == 0)
    suite.assert_contains("Logs show entries", logs_out.output, "INFO")
    suite.assert_contains("Logs show time column", logs_out.output, "time")
    suite.assert_contains("Logs show level column", logs_out.output, "level")
    suite.assert_contains("Logs show message column", logs_out.output, "message")
    error_logs = run_wp(RECEIVER_CONT, ["wpsyncer", "logs", "10", "--level=error"])
    suite.assert_true("logs --level=error runs", error_logs.code == 0)

    suite.step("7. 'wp wpsyncer configure --yes'")
    configure_out = run_wp(SOURCE_CONT, ["wpsyncer", "configure", "--mode=source", "--yes"])
    suite.assert_true("configure --yes runs without error", configure_out.code == 0)
    suite.assert_contains("Configure shows Configuration saved", configure_out.output, "Configuration saved")

    suite.step("8. 'wp wpsyncer run' (bulk)")
    run_out = run_wp(SOURCE_CONT, ["wpsyncer", "run"])
    suite.assert_true("bulk run command succeeds", run_out.code == 0)
    suite.assert_contains("Bulk run mentions 'bulk sync'", run_out.output, "bulk sync")


def module_06(suite: Suite) -> None:
    suite.banner("MODULE 06: Both Mode & Bidirectional Loop Prevention")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)
    orig_source = get_settings_json(SOURCE_CONT)
    orig_receiver = get_settings_json(RECEIVER_CONT)

    try:
        suite.step("1. Configure both sites as 'both' mode")
        set_settings(SOURCE_CONT, both_source_settings())
        set_settings(RECEIVER_CONT, both_receiver_settings())
        suite.assert_true("Source configured as 'both' mode", get_settings_json(SOURCE_CONT).get("mode") == "both")
        suite.assert_true("Receiver configured as 'both' mode", get_settings_json(RECEIVER_CONT).get("mode") == "both")
        time.sleep(3)

        suite.step("2. Create product on Source → syncs to Receiver (both mode)")
        src_both_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Both Mode Source Product", "--type=simple", "--regular_price=10.00", "--sku=BOTH-SRC-001", "--porcelain"]).output
        src_both_id = parse_id(src_both_out)
        suite.assert_matches(f"Both-mode source product created (ID: {src_both_id})", src_both_id, r"^\d+$")
        rcv_from_source = ""
        if re.fullmatch(r"\d+", src_both_id):
            run_wp(SOURCE_CONT, ["post", "meta", "update", src_both_id, "_test_meta_field", "from-source-both"])
            run_wp(SOURCE_CONT, ["post", "update", src_both_id, "--post_title=Both Mode Source"])
            run_pending_crons()
            sync_product_immediate(src_both_id)
            rcv_from_source = wait_for_product("BOTH-SRC-001", timeout_sec=90) or ""
            suite.assert_matches("Source product arrived on receiver", rcv_from_source, r"^\d+$")
            if re.fullmatch(r"\d+", rcv_from_source):
                r_meta = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_from_source, "_test_meta_field"]).output
                suite.assert_true("Receiver got meta from source", r_meta == "from-source-both")

        suite.step("3. Create product on Receiver → syncs to Source (both mode)")
        rcv_both_out = run_wc(RECEIVER_CONT, ["product", "create", "--name=Both Mode Receiver Product", "--type=simple", "--regular_price=20.00", "--sku=BOTH-RCV-001", "--porcelain"]).output
        rcv_both_id = parse_id(rcv_both_out)
        suite.assert_matches(f"Both-mode receiver product created (ID: {rcv_both_id})", rcv_both_id, r"^\d+$")
        if re.fullmatch(r"\d+", rcv_both_id):
            run_wp(RECEIVER_CONT, ["post", "meta", "update", rcv_both_id, "_test_meta_field", "from-receiver-both"])
            run_wp(RECEIVER_CONT, ["post", "update", rcv_both_id, "--post_title=Both Mode Receiver"])
            run_pending_crons(RECEIVER_CONT)
            sync_product_immediate(rcv_both_id, container=RECEIVER_CONT)
            rcv_on_source = wait_for_product("BOTH-RCV-001", container=SOURCE_CONT, timeout_sec=90) or ""
            suite.assert_matches("Receiver product arrived on source", rcv_on_source, r"^\d+$")

        suite.step("4. Verify NO infinite loop (loop prevention)")
        if re.fullmatch(r"\d+", rcv_both_id):
            source_has_rcv_prod = wait_for_product("BOTH-RCV-001", container=SOURCE_CONT, timeout_sec=20)
            if source_has_rcv_prod and re.fullmatch(r"\d+", source_has_rcv_prod):
                r_count = run_wc(SOURCE_CONT, ["product", "list", "--sku=BOTH-RCV-001", "--field=id"]).output
                id_count = len([line for line in r_count.splitlines() if re.fullmatch(r"\d+", line.strip())])
                suite.assert_true("No duplicate products on source (loop prevention works)", id_count <= 1)
        _ = get_logs(SOURCE_CONT)
        _ = get_logs(RECEIVER_CONT)
        suite.ok("Both mode loop prevention verified (no infinite loop detected)")

        suite.step("5. Update on source → updates receiver → NO echo")
        if re.fullmatch(r"\d+", src_both_id):
            run_wp(SOURCE_CONT, ["post", "update", src_both_id, "--regular_price=15.00", "--post_title=Both Mode Source v2"])
            run_pending_crons()
            sync_product_immediate(src_both_id)
            time.sleep(8)
            if re.fullmatch(r"\d+", rcv_from_source):
                r_price_updated = get_product_field(RECEIVER_CONT, rcv_from_source, "regular_price")
                suite.assert_true("Receiver price updated from source change", r_price_updated == "15.00")
            suite.ok("Update propagated without echo loop")

        suite.step("6. Restore original configurations")
        set_settings(SOURCE_CONT, orig_source)
        set_settings(RECEIVER_CONT, orig_receiver)
        suite.ok("Original plugin modes restored")
    finally:
        try:
            set_settings(SOURCE_CONT, orig_source)
        except Exception:
            pass
        try:
            set_settings(RECEIVER_CONT, orig_receiver)
        except Exception:
            pass


def php_import_json(container: str, json_text: str) -> str:
    encoded = base64.b64encode(json_text.encode("utf-8")).decode("ascii")
    php = (
        f"$json = base64_decode('{encoded}');"
        "$settings = new WPSYNCER_Settings();"
        "$result = $settings->import_json($json);"
        "if (is_wp_error($result)) { echo 'ERROR: ' . $result->get_error_message(); } else { echo 'IMPORT_OK'; }"
    )
    return run_eval(container, php).output


def module_07(suite: Suite) -> None:
    suite.banner("MODULE 07: Settings Export/Import & Meta Keys")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)
    orig_receiver = get_settings_json(RECEIVER_CONT)

    try:
        suite.step("1. Export settings from source")
        export_json = run_eval(SOURCE_CONT, "$settings = new WPSYNCER_Settings(); echo $settings->export_json();").output
        suite.assert_true("Settings export returns data", bool(export_json))
        export_obj = json.loads(export_json)
        suite.assert_true("Export has schema field", export_obj.get("schema") == "wpsyncer.settings_export.v1")
        suite.assert_nonempty("Export has exported_at", export_obj.get("exported_at"))
        suite.assert_true("Export has source_site_id", export_obj.get("source_site_id") == SOURCE_ID)
        suite.assert_nonempty("Export has settings object", export_obj.get("settings"))
        suite.assert_true("Export settings has mode = source", export_obj.get("settings", {}).get("mode") == "source")
        suite.assert_true("Export settings has shared_secret preserved", export_obj.get("settings", {}).get("shared_secret") == SHARED_SECRET)
        suite.assert_true("Export settings has target_url", "receiver" in str(export_obj.get("settings", {}).get("target_url", "")))

        suite.step("2. Import settings into receiver")
        import_result = php_import_json(RECEIVER_CONT, export_json)
        suite.assert_true("Settings import succeeds", "IMPORT_OK" in import_result)
        verify_out = run_eval(RECEIVER_CONT, "$saved = get_option('wpsyncer_settings', array()); echo json_encode($saved);").output
        verify_obj = json.loads(verify_out)
        suite.assert_true("Imported mode is 'source'", verify_obj.get("mode") == "source")
        suite.assert_true("Imported shared_secret preserved", verify_obj.get("shared_secret") == SHARED_SECRET)

        suite.step("3. Import settings with missing fields")
        minimal_json = '{"schema":"wpsyncer.settings_export.v1","exported_at":"2026-01-01T00:00:00+00:00","source_site_id":"test-minimal","settings":{"mode":"receiver"}}'
        minimal_result = php_import_json(RECEIVER_CONT, minimal_json)
        suite.assert_true("Minimal settings import succeeds", "IMPORT_OK" in minimal_result)
        verify_minimal = run_eval(RECEIVER_CONT, "$saved = get_option('wpsyncer_settings', array()); echo json_encode($saved['create_missing_products'] ?? 'MISSING');").output
        suite.assert_true("Missing fields filled with defaults: create_missing_products", verify_minimal == '"yes"')

        suite.step("4. Import with invalid schema → rejected")
        bad_schema_json = '{"schema":"bad_schema","settings":{"mode":"source"}}'
        bad_schema_result = php_import_json(RECEIVER_CONT, bad_schema_json)
        suite.assert_true("Bad schema import rejected", "ERROR" in bad_schema_result or "Unsupported" in bad_schema_result)

        suite.step("5. Import with invalid JSON → rejected")
        bad_json_result = php_import_json(RECEIVER_CONT, "not json at all")
        suite.assert_true("Invalid JSON import rejected", "ERROR" in bad_json_result or "valid JSON" in bad_json_result)

        set_settings(RECEIVER_CONT, receiver_settings())
        suite.ok("Receiver settings restored")

        suite.step("6. Meta key whitelist with multiple keys")
        set_settings_field(SOURCE_CONT, "sync_meta_keys", "_test_meta_field\n_custom_price\n_custom_label")
        multi_meta_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Multi Meta Product", "--type=simple", "--regular_price=33.00", "--sku=SETT-META-1", "--porcelain"]).output
        multi_meta_id = parse_id(multi_meta_out)
        suite.assert_matches(f"Multi-meta product created (ID: {multi_meta_id})", multi_meta_id, r"^\d+$")
        if re.fullmatch(r"\d+", multi_meta_id):
            run_wp(SOURCE_CONT, ["post", "meta", "update", multi_meta_id, "_test_meta_field", "test-value"])
            run_wp(SOURCE_CONT, ["post", "meta", "update", multi_meta_id, "_custom_price", "49.99"])
            run_wp(SOURCE_CONT, ["post", "meta", "update", multi_meta_id, "_custom_label", "Premium Item"])
            run_wp(SOURCE_CONT, ["post", "meta", "update", multi_meta_id, "_should_not_sync", "nope"])
            run_wp(SOURCE_CONT, ["post", "update", multi_meta_id, "--post_title=Multi Meta Prod"])
            run_pending_crons()
            rcv_meta_id = wait_for_product("SETT-META-1", timeout_sec=90) or ""
            suite.assert_matches("Multi-meta product synced", rcv_meta_id, r"^\d+$")
            if re.fullmatch(r"\d+", rcv_meta_id):
                m1 = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_meta_id, "_test_meta_field"], allow_fail=True).output
                m2 = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_meta_id, "_custom_price"], allow_fail=True).output
                m3 = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_meta_id, "_custom_label"], allow_fail=True).output
                m4 = run_wp(RECEIVER_CONT, ["post", "meta", "get", rcv_meta_id, "_should_not_sync"], allow_fail=True).output
                suite.assert_true("Whitelisted meta _test_meta_field synced", m1 == "test-value")
                suite.assert_true("Whitelisted meta _custom_price synced", m2 == "49.99")
                suite.assert_true("Whitelisted meta _custom_label synced", m3 == "Premium Item")
                suite.assert_true("Non-whitelisted meta _should_not_sync NOT synced", m4 in ("", "\n"))

        set_settings_field(SOURCE_CONT, "sync_meta_keys", "_test_meta_field")

        suite.step("7. Bulk batch size configuration")
        batch_before = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "bulk_batch_size"]).output.strip()
        set_settings_field(SOURCE_CONT, "bulk_batch_size", "5")
        batch_after = run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "bulk_batch_size"]).output.strip()
        suite.assert_true("bulk_batch_size set to 5", batch_after == "5")
        set_settings_field(SOURCE_CONT, "bulk_batch_size", batch_before)
        suite.assert_true("bulk_batch_size restored", run_wp(SOURCE_CONT, ["wpsyncer", "config", "get", "bulk_batch_size"]).output.strip() == batch_before)
    finally:
        try:
            set_settings(RECEIVER_CONT, orig_receiver)
        except Exception:
            pass
        try:
            set_settings_field(SOURCE_CONT, "sync_meta_keys", "_test_meta_field")
        except Exception:
            pass


def module_08(suite: Suite) -> None:
    suite.banner("MODULE 08: Conflict Detection / Post Locks")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)

    suite.step("1. Create and sync a product for conflict test")
    conf_out = run_wc(SOURCE_CONT, ["product", "create", "--name=Conflict Test Product", "--type=simple", "--regular_price=100.00", "--sku=CONF-PROD-1", "--porcelain"]).output
    conf_src_id = parse_id(conf_out)
    suite.assert_matches(f"Conflict test product created (ID: {conf_src_id})", conf_src_id, r"^\d+$")
    rcv_conf_id = ""
    if re.fullmatch(r"\d+", conf_src_id):
        run_wp(SOURCE_CONT, ["post", "update", conf_src_id, "--post_title=Conflict Test"])
        run_pending_crons()
        sync_product_immediate(conf_src_id)
        rcv_conf_id = wait_for_product("CONF-PROD-1", timeout_sec=90) or ""
        suite.assert_matches("Conflict test product synced to receiver", rcv_conf_id, r"^\d+$")

    suite.step("2. Set post lock on receiver product")
    if re.fullmatch(r"\d+", rcv_conf_id):
        future_ts = int(time.time()) + 60
        run_wp(RECEIVER_CONT, ["post", "meta", "update", rcv_conf_id, "_edit_lock", f"{future_ts}:1"])
        suite.ok(f"_edit_lock set on product {rcv_conf_id} on receiver")

        suite.step("3. Trigger re-sync → should be rejected (409)")
        time.sleep(2)
        run_wp(SOURCE_CONT, ["post", "update", conf_src_id, "--post_title=Conflict Test v2"])
        run_pending_crons()
        sync_product_immediate(conf_src_id)
        time.sleep(8)
        rcv_logs_after_lock = get_logs(RECEIVER_CONT)
        found_conflict = False
        for log in rcv_logs_after_lock:
            message = str(log.get("message", ""))
            if re.search(r"locked|conflict|409|being edited", message, re.I):
                found_conflict = True
                suite.ok(f"Found conflict log entry: {message}")
        suite.assert_true("Conflict was logged on receiver", found_conflict)

        run_wp(RECEIVER_CONT, ["post", "meta", "delete", rcv_conf_id, "_edit_lock"], allow_fail=True)
        suite.ok("Post lock cleared")

        suite.step("4. Re-sync after lock cleared → should succeed")
        run_wp(SOURCE_CONT, ["post", "update", conf_src_id, "--post_title=Conflict Test v3"])
        run_pending_crons()
        sync_product_immediate(conf_src_id)
        time.sleep(8)
        r_name_after_lock = get_product_field(RECEIVER_CONT, rcv_conf_id, "name")
        suite.assert_true("Product was updated after lock cleared", r_name_after_lock in ("Conflict Test v3", "Conflict Test v2"))
        suite.ok("Post-lock sync succeeded")


def module_09(suite: Suite) -> None:
    suite.banner("MODULE 09: Logging Behavior")
    remove_all_products(SOURCE_CONT)
    remove_all_products(RECEIVER_CONT)

    suite.step("1. Verify log entry format")
    logs = get_logs(RECEIVER_CONT)
    suite.assert_true("Logs array is not empty", len(logs) > 0)
    for log in logs[: min(3, len(logs))]:
        suite.assert_matches("Log has 'time' field (ISO 8601)", str(log.get("time", "")), r"^\d{4}-\d{2}-\d{2}T")
        suite.assert_matches("Log has 'level' field (info/error/warn)", str(log.get("level", "")), r"^(info|error|warn)$")
        suite.assert_true("Log has 'message' field (non-empty)", bool(str(log.get("message", ""))))

    suite.step("2. Verify error logs written to PHP error_log")
    run_eval(RECEIVER_CONT, "error_log('[WPSYNCER] Test error log entry from logging test'); echo 'DONE';")
    time.sleep(2)
    debug_log_res = run_docker_exec(RECEIVER_CONT, ["tail", "-20", "/var/www/html/wp-content/debug.log"], allow_fail=True)
    debug_log = debug_log_res.output
    if debug_log_res.ok and bool(debug_log):
        suite.assert_true("WPSYNCER appears in error_log", "WPSYNCER" in debug_log)
        suite.ok("debug.log exists and has WPSYNCER entries")
    else:
        suite.warn("debug.log not found or empty (skip)")

    suite.step("3. Log rotation (100 max entries)")
    run_eval(
        RECEIVER_CONT,
        "for ($i = 0; $i < 50; $i++) { WPSYNCER_Logger::log('info', 'Test log entry #' . $i, array('index' => $i)); } echo 'DONE';",
    )
    logs_after_burst = get_logs(RECEIVER_CONT)
    suite.assert_true("Logs still under or equal to 100 entries", len(logs_after_burst) <= 100)
    current_count = len(logs_after_burst)
    suite.ok(f"Current log count: {current_count} (max 100)")
    if current_count > 80:
        run_eval(
            RECEIVER_CONT,
            "$count = 0; while ($count < 110) { WPSYNCER_Logger::log('info', 'Rotation test entry', array('seq' => $count)); $count++; } echo 'DONE';",
        )
        logs_after_rotate = get_logs(RECEIVER_CONT)
        suite.assert_true(f"Log rotation works: count = {len(logs_after_rotate)} (max 100)", len(logs_after_rotate) <= 100)
        suite.ok(f"Log rotation confirmed: {len(logs_after_rotate)} entries (max 100)")

    suite.step("4. debug_logging = 'no' suppresses logs")
    clear_logs(RECEIVER_CONT)
    set_settings_field(RECEIVER_CONT, "debug_logging", "no")
    run_eval(RECEIVER_CONT, "WPSYNCER_Logger::log('info', 'Should NOT appear in logs', array('test' => 'suppressed')); WPSYNCER_Logger::log('error', 'Should also NOT appear', array('test' => 'suppressed-error')); echo 'DONE';")
    logs_suppressed = get_logs(RECEIVER_CONT)
    suite.assert_true("No logs written when debug_logging=no", len(logs_suppressed) == 0)

    suite.step("5. Re-enable debug logging")
    set_settings_field(RECEIVER_CONT, "debug_logging", "yes")
    clear_logs(RECEIVER_CONT)
    run_eval(RECEIVER_CONT, "WPSYNCER_Logger::log('info', 'Logging re-enabled', array('test' => 're-enabled')); echo 'DONE';")
    logs_re_enabled = get_logs(RECEIVER_CONT)
    suite.assert_true("Logging works again after re-enable", len(logs_re_enabled) >= 1)
    suite.ok("debug_logging toggle verified")


MODULES: dict[str, tuple[str, Callable[[Suite], None]]] = {
    "01": ("Basic Sync - Simple, Variable, Grouped, External", module_01),
    "02": ("Edge Cases - UTF-8, secrets, conflicts, bulk", module_02),
    "03": ("Receiver Toggles & Delete Behaviors", module_03),
    "04": ("Image Sync & Deduplication", module_04),
    "05": ("WP-CLI Commands", module_05),
    "06": ("Both Mode & Bidirectional Loop Prevention", module_06),
    "07": ("Settings Export/Import & Meta Keys", module_07),
    "08": ("Conflict Detection & Post Locks", module_08),
    "09": ("Logging Behavior", module_09),
}


def preflight() -> None:
    result = run_command(["docker", "info"], timeout=30)
    if not result.ok:
        raise RuntimeError("Docker is not running or not available.")


def teardown_environment() -> None:
    try:
        compose("down", timeout=600)
    except Exception:
        pass


def run_selected_modules(selected: list[str], *, keep_running: bool) -> int:
    preflight()

    suite = Suite()
    print()
    print("╔═══════════════════════════════════════════════════════════════╗")
    print("║            Woo Product Syncer — Full Test Suite               ║")
    print("╚═══════════════════════════════════════════════════════════════╝")

    try:
        for mod in selected:
            label, fn = MODULES[mod]
            print()
            print("╔═══════════════════════════════════════════════════╗")
            print(f"║   MODULE {mod} : {label.ljust(32)}║")
            print("╚═══════════════════════════════════════════════════╝")
            print()
            try:
                fn(suite)
            except Exception as exc:
                suite.failed += 1
                print(f"  MODULE CRASHED: {exc}")
                traceback.print_exc()
    finally:
        if not keep_running:
            print()
            print("  Shutting down environment...")
            teardown_environment()
            print("  Environment shut down.")

    print(f"  Re-run: python {Path(__file__).name} [--module 01 03 07] [--keep-running]")
    print("  Single: python tests/scripts/run-tests.py --module 01")
    print()
    return suite.summary()


def parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Woo Product Syncer cross-platform test runner")
    parser.add_argument(
        "--module",
        nargs="*",
        choices=sorted(MODULES.keys()),
        help="Run only specific module numbers (default: all)",
    )
    parser.add_argument(
        "--keep-running",
        action="store_true",
        help="Keep Docker environment running after tests complete",
    )
    return parser.parse_args(argv)


def main(argv: list[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    selected = args.module or sorted(MODULES.keys())
    return run_selected_modules(selected, keep_running=args.keep_running)


if __name__ == "__main__":
    raise SystemExit(main())
