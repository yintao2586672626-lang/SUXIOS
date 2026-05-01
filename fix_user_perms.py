with open(r'd:\桌面\国际\JD-main\HOTEL\public\index.html', 'r', encoding='utf-8') as f:
    content = f.read()

# Fix 1: Edit button permission
old1 = ':can-edit="user?.is_super_admin || (user?.role_id === 2 && u.role_id === 3 && u.hotel_id === user?.hotel_id)"'
new1 = ':can-edit="user?.is_super_admin || user?.role_id <= 2"'
content = content.replace(old1, new1)

# Fix 2: Delete button permission
old2 = ":can-delete=\"u.id !== user?.id && (user?.is_super_admin || (user?.role_id === 2 && u.role_id === 3 && u.hotel_id === user?.hotel_id))\""
new2 = ':can-delete="u.id !== user?.id && (user?.is_super_admin || user?.role_id <= 2)"'
content = content.replace(old2, new2)

with open(r'd:\桌面\国际\JD-main\HOTEL\public\index.html', 'w', encoding='utf-8') as f:
    f.write(content)
print('Done - fixed edit/delete button permissions')
