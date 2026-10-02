#!/usr/bin/env python3
"""Structural sanity check for PHP sources, for environments with no PHP.

`php -l` is the right tool and CI uses it. This exists because the agent
sandbox this fork is maintained from has no PHP and cannot install one, so an
edit could otherwise only be eyeballed. It caught two real self-inflicted bugs
that reading the diff did not:

  * a generator that emitted `$a[\\'k\\']` -- an escaped quote in code context,
    which is a parse error PHP reports a dozen lines later;
  * a `*/` inside a docblock (`debian_*/ubuntu_*`), which closed the comment
    early and left prose as code.

What it checks, after stripping comments and string literals:
  1. every `/*` is closed;
  2. no `\\'` or `\\"` in code context (a backslash there is only ever a
     namespace separator, as in `\\Exception` or `Core\\Updates`);
  3. no stray `*/`;
  4. brackets, braces and parentheses balance.

What it does NOT check: anything semantic, heredocs (it warns when it sees
`<<<`), or whether the code is correct. A pass here means "plausibly parses",
not "works". Always confirm against the known-good version from git as a
control -- a checker that passes everything is worth nothing:

    git show HEAD:path/to/File.php > /tmp/base.php
    python3 build/php-structure-check.py path/to/File.php /tmp/base.php

Exit status is 1 on the first problem found.
"""

import sys

PATH = '?'


def strip(src):
    """Return the code with comments and string literals removed."""
    out, i, n = [], 0, len(src)
    while i < n:
        c = src[i]
        if c == '/' and i + 1 < n and src[i + 1] == '/':
            while i < n and src[i] != '\n':
                i += 1
        elif c == '#' and not (i + 1 < n and src[i + 1] == '['):
            while i < n and src[i] != '\n':
                i += 1
        elif c == '/' and i + 1 < n and src[i + 1] == '*':
            j = src.find('*/', i + 2)
            if j == -1:
                fail("unterminated /* comment", src[:i].count('\n') + 1)
            i = j + 2
        elif c == '\\':
            if i + 1 < n and src[i + 1] in "'\"":
                fail("escaped quote in code context", src[:i].count('\n') + 1)
            out.append(c)
            i += 1
        elif c in "'\"":
            quote, start, i = c, i, i + 1
            while i < n:
                if src[i] == '\\':
                    i += 2
                    continue
                if src[i] == quote:
                    i += 1
                    break
                i += 1
            else:
                fail("unterminated %s string" % quote, src[:start].count('\n') + 1)
            out.append('""')
        else:
            out.append(c)
            i += 1
    return ''.join(out)


def fail(message, line):
    sys.stderr.write("ERROR %s:%d: %s\n" % (PATH, line, message))
    raise SystemExit(1)


def stray_comment_end(code):
    j = code.find('*/')
    if j != -1:
        fail("stray '*/' -- a '*/' inside a docblock closes it early",
             code[:j].count('\n') + 1)


def balance(code):
    pairs, stack, line = {')': '(', '}': '{', ']': '['}, [], 1
    for ch in code:
        if ch == '\n':
            line += 1
        elif ch in '([{':
            stack.append((ch, line))
        elif ch in ')]}':
            if not stack or stack[-1][0] != pairs[ch]:
                fail("unbalanced '%s'" % ch, line)
            stack.pop()
    if stack:
        fail("unclosed '%s'" % stack[-1][0], stack[-1][1])


def main(paths):
    if not paths:
        sys.stderr.write(__doc__.split('\n\n')[0] + '\n\n'
                         'usage: php-structure-check.py FILE.php [FILE.php ...]\n')
        return 2

    global PATH
    for path in paths:
        PATH = path
        with open(path, encoding='utf-8') as handle:
            src = handle.read()
        if '<<<' in src:
            print("note: %s contains a heredoc; that region is not checked" % path)
        code = strip(src)
        stray_comment_end(code)
        balance(code)
        print("OK  %-52s braces=%d" % (path, code.count('{')))
    return 0


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
