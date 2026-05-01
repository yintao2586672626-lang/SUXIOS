with open(r'd:\桌面\国际\JD-main\hotelx_mysql55.sql', 'r', encoding='utf-8') as f:
    content = f.read()

# Fix TYPE=InnoDB -> ENGINE=InnoDB (MySQL 5.5 uses ENGINE)
content = content.replace('TYPE=InnoDB', 'ENGINE=InnoDB')

with open(r'd:\桌面\国际\JD-main\hotelx_mysql55.sql', 'w', encoding='utf-8') as f:
    f.write(content)
print('Done - fixed TYPE->ENGINE')
