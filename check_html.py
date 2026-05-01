with open(r'd:\桌面\国际\JD-main\HOTEL\public\index.html', 'r', encoding='utf-8') as f:
    content = f.read()

# Check div balance in users section
section = content[1813:1900]
open_divs = section.count('<div') 
close_divs = section.count('</div>')
print(f'Open divs: {open_divs}, Close divs: {close_divs}, Balance: {open_divs - close_divs}')

# Find DataTable
dt = content.find('DataTable')
dt2 = content.find('</DataTable>', dt)
print(f'DataTable at: {dt}, closing at: {dt2}')

# Check if DataTable exists and has data
if 'filteredUsers' in content[1860:1870]:
    print('filteredUsers reference found OK')

# The real issue might be that the file is too large and Vue can't parse it
# Let's check if there are any obvious broken tags around line 9200
line_9200 = content.split('\n')[9199]
print(f'\nLine 9200: {line_9200[:100]}')
