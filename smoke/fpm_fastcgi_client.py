#!/usr/bin/env python3
"""Small FastCGI client for the installed PHP-FPM request-end smoke."""

import json
import socket
import struct
import sys
import time


def record(kind: int, content: bytes = b"") -> bytes:
    return struct.pack("!BBHHBB", 1, kind, 1, len(content), 0, 0) + content


def read_exact(connection: socket.socket, length: int) -> bytes:
    data = bytearray()
    while len(data) < length:
        part = connection.recv(length - len(data))
        if not part:
            raise RuntimeError("FastCGI connection closed early")
        data.extend(part)
    return bytes(data)


def param(name: str, value: str) -> bytes:
    name_bytes = name.encode()
    value_bytes = value.encode()
    if len(name_bytes) >= 128 or len(value_bytes) >= 128:
        raise ValueError("Smoke FastCGI parameter is too long")
    return bytes([len(name_bytes), len(value_bytes)]) + name_bytes + value_bytes


def main() -> None:
    port = int(sys.argv[1])
    deadline = time.monotonic() + 10
    while True:
        try:
            connection = socket.create_connection(("127.0.0.1", port), timeout=2)
            break
        except OSError:
            if time.monotonic() >= deadline:
                raise
            time.sleep(0.1)

    with connection:
        connection.settimeout(5)
        parameters = b"".join(
            param(name, value)
            for name, value in {
                "SCRIPT_FILENAME": "/app/smoke/fpm_request.php",
                "REQUEST_METHOD": "GET",
                "SERVER_PROTOCOL": "HTTP/1.1",
                "QUERY_STRING": "",
            }.items()
        )
        started = time.monotonic()
        connection.sendall(record(1, struct.pack("!HB5s", 1, 0, b"\0" * 5)))
        connection.sendall(record(4, parameters) + record(4) + record(5))
        output = bytearray()
        errors = bytearray()
        while True:
            version, kind, request_id, length, padding, _ = struct.unpack(
                "!BBHHBB", read_exact(connection, 8)
            )
            if version != 1 or request_id != 1:
                raise RuntimeError("Invalid FastCGI response")
            content = read_exact(connection, length)
            if padding:
                read_exact(connection, padding)
            if kind == 6:
                output.extend(content)
            elif kind == 7:
                errors.extend(content)
            elif kind == 3:
                break
        elapsed = time.monotonic() - started

    if errors:
        raise RuntimeError(errors.decode(errors="replace"))
    _, separator, body = bytes(output).partition(b"\r\n\r\n")
    if not separator:
        raise RuntimeError("Missing FastCGI response headers")
    result = json.loads(body)
    if result != {
        "inline_calls": 0,
        "request_end_calls": 1,
        "event_types": ["backend_exception", "error_suppressed"],
        "suppressed_count": 30,
    }:
        raise RuntimeError(f"Unexpected FPM capture result: {result}")
    if elapsed >= 1.5:
        raise RuntimeError(f"FPM request exceeded 1.5-second smoke budget: {elapsed:.3f}s")
    print(f"PHP-FPM request-end smoke passed in {elapsed:.3f}s")


if __name__ == "__main__":
    main()
