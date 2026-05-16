from pathlib import Path
import sys


PROJECT_ROOT = Path(__file__).resolve().parent
HTML_PATH = PROJECT_ROOT / "public" / "index.html"

if not HTML_PATH.exists():
    print(f"Error: public/index.html not found at {HTML_PATH}", file=sys.stderr)
    sys.exit(1)

content = HTML_PATH.read_text(encoding="utf-8")

# Check div balance in users section
section = content[1813:1900]
open_divs = section.count("<div")
close_divs = section.count("</div>")
print(f"Open divs: {open_divs}, Close divs: {close_divs}, Balance: {open_divs - close_divs}")

# Find DataTable
dt = content.find("DataTable")
dt2 = content.find("</DataTable>", dt)
print(f"DataTable at: {dt}, closing at: {dt2}")

# Check if DataTable exists and has data
if "filteredUsers" in content[1860:1870]:
    print("filteredUsers reference found OK")

# The real issue might be that the file is too large and Vue can't parse it
# Let's check if there are any obvious broken tags around line 9200
lines = content.split("\n")
if len(lines) >= 9200:
    line_9200 = lines[9199]
    print(f"\nLine 9200: {line_9200[:100]}")
else:
    print(f"\nLine 9200: unavailable, file has {len(lines)} lines")
