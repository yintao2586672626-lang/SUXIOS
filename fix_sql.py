with open(r'd:\桌面\国际\JD-main\hotelx_mysql55.sql', 'r', encoding='latin1') as f:
    content = f.read()

replacements = [
    ('DEFAULT current_timestamp()', "DEFAULT '0000-00-00 00:00:00'"),
    ('DEFAULT CURRENT_TIMESTAMP()', "DEFAULT '0000-00-00 00:00:00'"),
    ("SET TIME_ZONE='+00:00'", ''),
]

for old, new in replacements:
    content = content.replace(old, new)

# Remove TIME_ZONE lines
lines = content.split('\n')
lines = [l for l in lines if 'TIME_ZONE' not in l or 'OLD' in l]
content = '\n'.join(lines)

with open(r'd:\桌面\国际\JD-main\hotelx_mysql55.sql', 'w', encoding='utf-8') as f:
    f.write(content)
print('Done - all fixes applied')
