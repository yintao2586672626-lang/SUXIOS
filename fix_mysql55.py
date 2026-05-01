import re

with open(r'd:\桌面\国际\JD-main\hotelx_dump.sql', 'rb') as f:
    raw = f.read()

# Try to decode with various encodings
for enc in ['utf-8-sig', 'gbk', 'latin1', 'utf-16']:
    try:
        content = raw.decode(enc)
        print(f'Using encoding: {enc}')
        break
    except:
        continue
else:
    # Force decode, replace errors
    content = raw.decode('utf-8', errors='replace')

# Fix 1: Replace CURRENT_TIMESTAMP defaults
content = re.sub(r"DEFAULT CURRENT_TIMESTAMP", "DEFAULT '0000-00-00 00:00:00'", content)

# Fix 2: Remove TIME_ZONE lines
lines = content.split('\n')
lines = [l for l in lines if 'TIME_ZONE' not in l]
content = '\n'.join(lines)

# Fix 3: utf8mb4 -> utf8
content = content.replace('utf8mb4', 'utf8')

# Fix 4: Permissive SQL_MODE
content = content.replace("SQL_MODE='NO_AUTO_VALUE_ON_ZERO'", "SQL_MODE=''")

with open(r'd:\桌面\国际\JD-main\hotelx_mysql55.sql', 'w', encoding='utf-8') as f:
    f.write(content)

print('Done - saved to hotelx_mysql55.sql')
