window.SUXI_EXPANSION_STATIC = (() => {
    const marketEvaluationCityTierOptions = ['一线', '二线', '三线', '四线'];
    const marketEvaluationCityOptions = [
        { tier: '一线', names: ['北京', '上海', '广州', '深圳'] },
        { tier: '二线', names: ['成都', '重庆', '杭州', '武汉', '西安', '苏州', '南京', '天津', '郑州', '长沙', '东莞', '佛山', '宁波', '青岛', '沈阳', '济南', '合肥', '福州', '厦门', '昆明', '无锡', '大连', '哈尔滨', '长春', '南昌', '贵阳', '南宁', '太原', '石家庄', '常州', '温州', '泉州', '嘉兴', '南通'] },
        { tier: '三线', names: ['徐州', '扬州', '绍兴', '台州', '金华', '烟台', '潍坊', '洛阳', '唐山', '保定', '珠海', '中山', '惠州', '汕头', '海口', '兰州', '银川', '西宁', '呼和浩特', '乌鲁木齐', '赣州', '临沂', '淄博', '芜湖', '襄阳', '宜昌', '镇江', '泰州', '湖州', '廊坊', '秦皇岛', '吉林', '柳州', '桂林', '三亚', '绵阳', '南充', '遵义'] },
        { tier: '四线', names: ['安庆', '蚌埠', '阜阳', '滁州', '马鞍山', '淮南', '开封', '新乡', '许昌', '焦作', '平顶山', '商丘', '信阳', '驻马店', '衡阳', '岳阳', '株洲', '湘潭', '常德', '郴州', '大庆', '齐齐哈尔', '牡丹江', '鞍山', '锦州', '营口', '德州', '聊城', '威海', '日照', '莆田', '漳州', '龙岩', '九江', '上饶', '宜春', '自贡', '泸州', '德阳', '乐山', '曲靖', '大理'] }
    ].flatMap(group => group.names.map(name => ({ name, tier: group.tier })));
    const strategyDistrictOptionsByCity = {
        北京: ['东城区', '西城区', '朝阳区', '丰台区', '石景山区', '海淀区', '门头沟区', '房山区', '通州区', '顺义区', '昌平区', '大兴区', '怀柔区', '平谷区', '密云区', '延庆区'],
        上海: ['黄浦区', '徐汇区', '长宁区', '静安区', '普陀区', '虹口区', '杨浦区', '闵行区', '宝山区', '嘉定区', '浦东新区', '金山区', '松江区', '青浦区', '奉贤区', '崇明区'],
        广州: ['荔湾区', '越秀区', '海珠区', '天河区', '白云区', '黄埔区', '番禺区', '花都区', '南沙区', '从化区', '增城区'],
        深圳: ['罗湖区', '福田区', '南山区', '宝安区', '龙岗区', '盐田区', '龙华区', '坪山区', '光明区'],
        成都: ['锦江区', '青羊区', '金牛区', '武侯区', '成华区', '龙泉驿区', '青白江区', '新都区', '温江区', '双流区', '郫都区', '新津区', '金堂县', '大邑县', '蒲江县', '都江堰市', '彭州市', '邛崃市', '崇州市', '简阳市'],
        重庆: ['万州区', '涪陵区', '渝中区', '大渡口区', '江北区', '沙坪坝区', '九龙坡区', '南岸区', '北碚区', '綦江区', '大足区', '渝北区', '巴南区', '黔江区', '长寿区', '江津区', '合川区', '永川区', '南川区', '璧山区', '铜梁区', '潼南区', '荣昌区', '开州区', '梁平区', '武隆区'],
        杭州: ['上城区', '拱墅区', '西湖区', '滨江区', '萧山区', '余杭区', '富阳区', '临安区', '临平区', '钱塘区', '桐庐县', '淳安县', '建德市'],
        武汉: ['江岸区', '江汉区', '硚口区', '汉阳区', '武昌区', '青山区', '洪山区', '东西湖区', '汉南区', '蔡甸区', '江夏区', '黄陂区', '新洲区'],
        西安: ['新城区', '碑林区', '莲湖区', '灞桥区', '未央区', '雁塔区', '阎良区', '临潼区', '长安区', '高陵区', '鄠邑区', '蓝田县', '周至县'],
        苏州: ['虎丘区', '吴中区', '相城区', '姑苏区', '吴江区', '苏州工业园区', '常熟市', '张家港市', '昆山市', '太仓市'],
        南京: ['玄武区', '秦淮区', '建邺区', '鼓楼区', '浦口区', '栖霞区', '雨花台区', '江宁区', '六合区', '溧水区', '高淳区'],
        天津: ['和平区', '河东区', '河西区', '南开区', '河北区', '红桥区', '东丽区', '西青区', '津南区', '北辰区', '武清区', '宝坻区', '滨海新区', '宁河区', '静海区', '蓟州区'],
        郑州: ['中原区', '二七区', '管城回族区', '金水区', '上街区', '惠济区', '中牟县', '郑州经济技术开发区', '郑州高新技术产业开发区', '郑州航空港经济综合实验区', '巩义市', '荥阳市', '新密市', '新郑市', '登封市'],
        长沙: ['芙蓉区', '天心区', '岳麓区', '开福区', '雨花区', '望城区', '长沙县', '浏阳市', '宁乡市'],
        东莞: ['东莞市'],
        佛山: ['禅城区', '南海区', '顺德区', '三水区', '高明区'],
        宁波: ['海曙区', '江北区', '北仑区', '镇海区', '鄞州区', '奉化区', '象山县', '宁海县', '余姚市', '慈溪市'],
        青岛: ['市南区', '市北区', '黄岛区', '崂山区', '李沧区', '城阳区', '即墨区', '胶州市', '平度市', '莱西市'],
        沈阳: ['和平区', '沈河区', '大东区', '皇姑区', '铁西区', '苏家屯区', '浑南区', '沈北新区', '于洪区', '辽中区', '康平县', '法库县', '新民市'],
        济南: ['历下区', '市中区', '槐荫区', '天桥区', '历城区', '长清区', '章丘区', '济阳区', '莱芜区', '钢城区', '平阴县', '商河县', '济南高新技术产业开发区'],
        合肥: ['瑶海区', '庐阳区', '蜀山区', '包河区', '长丰县', '肥东县', '肥西县', '庐江县', '合肥高新技术产业开发区', '合肥经济技术开发区', '合肥新站高新技术产业开发区', '巢湖市'],
        福州: ['鼓楼区', '台江区', '仓山区', '马尾区', '晋安区', '长乐区', '闽侯县', '连江县', '罗源县', '闽清县', '永泰县', '平潭县', '福清市'],
        厦门: ['思明区', '海沧区', '湖里区', '集美区', '同安区', '翔安区'],
        昆明: ['五华区', '盘龙区', '官渡区', '西山区', '东川区', '呈贡区', '晋宁区', '富民县', '宜良县', '石林彝族自治县', '嵩明县', '禄劝彝族苗族自治县', '寻甸回族彝族自治县', '安宁市'],
        无锡: ['锡山区', '惠山区', '滨湖区', '梁溪区', '新吴区', '江阴市', '宜兴市'],
        大连: ['中山区', '西岗区', '沙河口区', '甘井子区', '旅顺口区', '金州区', '普兰店区', '长海县', '瓦房店市', '庄河市'],
        哈尔滨: ['道里区', '南岗区', '道外区', '平房区', '松北区', '香坊区', '呼兰区', '阿城区', '双城区', '依兰县', '方正县', '宾县', '巴彦县', '木兰县', '通河县', '延寿县', '尚志市', '五常市'],
        长春: ['南关区', '宽城区', '朝阳区', '二道区', '绿园区', '双阳区', '九台区', '农安县', '长春经济技术开发区', '长春净月高新技术产业开发区', '长春高新技术产业开发区', '长春汽车经济技术开发区', '榆树市', '德惠市', '公主岭市'],
        南昌: ['东湖区', '西湖区', '青云谱区', '青山湖区', '新建区', '红谷滩区', '南昌县', '安义县', '进贤县'],
        贵阳: ['南明区', '云岩区', '花溪区', '乌当区', '白云区', '观山湖区', '开阳县', '息烽县', '修文县', '清镇市'],
        南宁: ['兴宁区', '青秀区', '江南区', '西乡塘区', '良庆区', '邕宁区', '武鸣区', '隆安县', '马山县', '上林县', '宾阳县', '横州市'],
        太原: ['小店区', '迎泽区', '杏花岭区', '尖草坪区', '万柏林区', '晋源区', '清徐县', '阳曲县', '娄烦县', '山西转型综合改革示范区', '古交市'],
        石家庄: ['长安区', '桥西区', '新华区', '井陉矿区', '裕华区', '藁城区', '鹿泉区', '栾城区', '井陉县', '正定县', '行唐县', '灵寿县', '高邑县', '深泽县', '赞皇县', '无极县', '平山县', '元氏县', '赵县', '石家庄高新技术产业开发区', '石家庄循环化工园区', '辛集市', '晋州市', '新乐市'],
        常州: ['天宁区', '钟楼区', '新北区', '武进区', '金坛区', '溧阳市'],
        温州: ['鹿城区', '龙湾区', '瓯海区', '洞头区', '永嘉县', '平阳县', '苍南县', '文成县', '泰顺县', '瑞安市', '乐清市', '龙港市'],
        泉州: ['鲤城区', '丰泽区', '洛江区', '泉港区', '惠安县', '安溪县', '永春县', '德化县', '金门县', '石狮市', '晋江市', '南安市'],
        嘉兴: ['南湖区', '秀洲区', '嘉善县', '海盐县', '海宁市', '平湖市', '桐乡市'],
        南通: ['通州区', '崇川区', '海门区', '如东县', '南通经济技术开发区', '启东市', '如皋市', '海安市'],
        徐州: ['鼓楼区', '云龙区', '贾汪区', '泉山区', '铜山区', '丰县', '沛县', '睢宁县', '徐州经济技术开发区', '新沂市', '邳州市'],
        扬州: ['广陵区', '邗江区', '江都区', '宝应县', '扬州经济技术开发区', '仪征市', '高邮市'],
        绍兴: ['越城区', '柯桥区', '上虞区', '新昌县', '诸暨市', '嵊州市'],
        台州: ['椒江区', '黄岩区', '路桥区', '三门县', '天台县', '仙居县', '温岭市', '临海市', '玉环市'],
        金华: ['婺城区', '金东区', '武义县', '浦江县', '磐安县', '兰溪市', '义乌市', '东阳市', '永康市'],
        烟台: ['芝罘区', '福山区', '牟平区', '莱山区', '蓬莱区', '烟台高新技术产业开发区', '烟台经济技术开发区', '龙口市', '莱阳市', '莱州市', '招远市', '栖霞市', '海阳市'],
        潍坊: ['潍城区', '寒亭区', '坊子区', '奎文区', '临朐县', '昌乐县', '潍坊滨海经济技术开发区', '青州市', '诸城市', '寿光市', '安丘市', '高密市', '昌邑市'],
        洛阳: ['老城区', '西工区', '瀍河回族区', '涧西区', '偃师区', '孟津区', '洛龙区', '新安县', '栾川县', '嵩县', '汝阳县', '宜阳县', '洛宁县', '伊川县', '洛阳高新技术产业开发区'],
        唐山: ['路南区', '路北区', '古冶区', '开平区', '丰南区', '丰润区', '曹妃甸区', '滦南县', '乐亭县', '迁西县', '玉田县', '河北唐山芦台经济开发区', '唐山市汉沽管理区', '唐山高新技术产业开发区', '河北唐山海港经济开发区', '遵化市', '迁安市', '滦州市'],
        保定: ['竞秀区', '莲池区', '满城区', '清苑区', '徐水区', '涞水县', '阜平县', '定兴县', '唐县', '高阳县', '容城县', '涞源县', '望都县', '安新县', '易县', '曲阳县', '蠡县', '顺平县', '博野县', '雄县', '保定高新技术产业开发区', '保定白沟新城', '涿州市', '定州市', '安国市', '高碑店市'],
        珠海: ['香洲区', '斗门区', '金湾区'],
        中山: ['中山市'],
        惠州: ['惠城区', '惠阳区', '博罗县', '惠东县', '龙门县'],
        汕头: ['龙湖区', '金平区', '濠江区', '潮阳区', '潮南区', '澄海区', '南澳县'],
        海口: ['秀英区', '龙华区', '琼山区', '美兰区'],
        兰州: ['城关区', '七里河区', '西固区', '安宁区', '红古区', '永登县', '皋兰县', '榆中县', '兰州新区'],
        银川: ['兴庆区', '西夏区', '金凤区', '永宁县', '贺兰县', '灵武市'],
        西宁: ['城东区', '城中区', '城西区', '城北区', '湟中区', '大通回族土族自治县', '湟源县'],
        呼和浩特: ['新城区', '回民区', '玉泉区', '赛罕区', '土默特左旗', '托克托县', '和林格尔县', '清水河县', '武川县', '呼和浩特经济技术开发区'],
        乌鲁木齐: ['天山区', '沙依巴克区', '新市区', '水磨沟区', '头屯河区', '达坂城区', '米东区', '乌鲁木齐县'],
        赣州: ['章贡区', '南康区', '赣县区', '信丰县', '大余县', '上犹县', '崇义县', '安远县', '定南县', '全南县', '宁都县', '于都县', '兴国县', '会昌县', '寻乌县', '石城县', '瑞金市', '龙南市'],
        临沂: ['兰山区', '罗庄区', '河东区', '沂南县', '郯城县', '沂水县', '兰陵县', '费县', '平邑县', '莒南县', '蒙阴县', '临沭县', '临沂高新技术产业开发区'],
        淄博: ['淄川区', '张店区', '博山区', '临淄区', '周村区', '桓台县', '高青县', '沂源县'],
        芜湖: ['镜湖区', '鸠江区', '弋江区', '湾沚区', '繁昌区', '南陵县', '芜湖经济技术开发区', '安徽芜湖三山经济开发区', '无为市'],
        襄阳: ['襄城区', '樊城区', '襄州区', '南漳县', '谷城县', '保康县', '老河口市', '枣阳市', '宜城市'],
        宜昌: ['西陵区', '伍家岗区', '点军区', '猇亭区', '夷陵区', '远安县', '兴山县', '秭归县', '长阳土家族自治县', '五峰土家族自治县', '宜都市', '当阳市', '枝江市'],
        镇江: ['京口区', '润州区', '丹徒区', '镇江新区', '丹阳市', '扬中市', '句容市'],
        泰州: ['海陵区', '高港区', '姜堰区', '兴化市', '靖江市', '泰兴市'],
        湖州: ['吴兴区', '南浔区', '德清县', '长兴县', '安吉县'],
        廊坊: ['安次区', '广阳区', '固安县', '永清县', '香河县', '大城县', '文安县', '大厂回族自治县', '廊坊经济技术开发区', '霸州市', '三河市'],
        秦皇岛: ['海港区', '山海关区', '北戴河区', '抚宁区', '青龙满族自治县', '昌黎县', '卢龙县', '秦皇岛市经济技术开发区', '北戴河新区'],
        吉林: ['昌邑区', '龙潭区', '船营区', '丰满区', '永吉县', '吉林经济开发区', '吉林高新技术产业开发区', '吉林中国新加坡食品区', '蛟河市', '桦甸市', '舒兰市', '磐石市'],
        柳州: ['城中区', '鱼峰区', '柳南区', '柳北区', '柳江区', '柳城县', '鹿寨县', '融安县', '融水苗族自治县', '三江侗族自治县'],
        桂林: ['秀峰区', '叠彩区', '象山区', '七星区', '雁山区', '临桂区', '阳朔县', '灵川县', '全州县', '兴安县', '永福县', '灌阳县', '龙胜各族自治县', '资源县', '平乐县', '恭城瑶族自治县', '荔浦市'],
        三亚: ['海棠区', '吉阳区', '天涯区', '崖州区'],
        绵阳: ['涪城区', '游仙区', '安州区', '三台县', '盐亭县', '梓潼县', '北川羌族自治县', '平武县', '江油市'],
        南充: ['顺庆区', '高坪区', '嘉陵区', '南部县', '营山县', '蓬安县', '仪陇县', '西充县', '阆中市'],
        遵义: ['红花岗区', '汇川区', '播州区', '桐梓县', '绥阳县', '正安县', '道真仡佬族苗族自治县', '务川仡佬族苗族自治县', '凤冈县', '湄潭县', '余庆县', '习水县', '赤水市', '仁怀市'],
        安庆: ['迎江区', '大观区', '宜秀区', '怀宁县', '太湖县', '宿松县', '望江县', '岳西县', '安徽安庆经济开发区', '桐城市', '潜山市'],
        蚌埠: ['龙子湖区', '蚌山区', '禹会区', '淮上区', '怀远县', '五河县', '固镇县', '蚌埠市高新技术开发区', '蚌埠市经济开发区'],
        阜阳: ['颍州区', '颍东区', '颍泉区', '临泉县', '太和县', '阜南县', '颍上县', '阜阳合肥现代产业园区', '阜阳经济技术开发区', '界首市'],
        滁州: ['琅琊区', '南谯区', '来安县', '全椒县', '定远县', '凤阳县', '中新苏滁高新技术产业开发区', '滁州经济技术开发区', '天长市', '明光市'],
        马鞍山: ['花山区', '雨山区', '博望区', '当涂县', '含山县', '和县'],
        淮南: ['大通区', '田家庵区', '谢家集区', '八公山区', '潘集区', '凤台县', '寿县'],
        开封: ['龙亭区', '顺河回族区', '鼓楼区', '禹王台区', '祥符区', '杞县', '通许县', '尉氏县', '兰考县'],
        新乡: ['红旗区', '卫滨区', '凤泉区', '牧野区', '新乡县', '获嘉县', '原阳县', '延津县', '封丘县', '新乡高新技术产业开发区', '新乡经济技术开发区', '新乡市平原城乡一体化示范区', '卫辉市', '辉县市', '长垣市'],
        许昌: ['魏都区', '建安区', '鄢陵县', '襄城县', '许昌经济技术开发区', '禹州市', '长葛市'],
        焦作: ['解放区', '中站区', '马村区', '山阳区', '修武县', '博爱县', '武陟县', '温县', '焦作城乡一体化示范区', '沁阳市', '孟州市'],
        平顶山: ['新华区', '卫东区', '石龙区', '湛河区', '宝丰县', '叶县', '鲁山县', '郏县', '平顶山高新技术产业开发区', '平顶山市城乡一体化示范区', '舞钢市', '汝州市'],
        商丘: ['梁园区', '睢阳区', '民权县', '睢县', '宁陵县', '柘城县', '虞城县', '夏邑县', '豫东综合物流产业聚集区', '河南商丘经济开发区', '永城市'],
        信阳: ['浉河区', '平桥区', '罗山县', '光山县', '新县', '商城县', '固始县', '潢川县', '淮滨县', '息县', '信阳高新技术产业开发区'],
        驻马店: ['驿城区', '西平县', '上蔡县', '平舆县', '正阳县', '确山县', '泌阳县', '汝南县', '遂平县', '新蔡县', '河南驻马店经济开发区'],
        衡阳: ['珠晖区', '雁峰区', '石鼓区', '蒸湘区', '南岳区', '衡阳县', '衡南县', '衡山县', '衡东县', '祁东县', '湖南衡阳松木经济开发区', '湖南衡阳高新技术产业园区', '耒阳市', '常宁市'],
        岳阳: ['岳阳楼区', '云溪区', '君山区', '岳阳县', '华容县', '湘阴县', '平江县', '岳阳市屈原管理区', '汨罗市', '临湘市'],
        株洲: ['荷塘区', '芦淞区', '石峰区', '天元区', '渌口区', '攸县', '茶陵县', '炎陵县', '醴陵市'],
        湘潭: ['雨湖区', '岳塘区', '湘潭县', '湖南湘潭高新技术产业园区', '湘潭昭山示范区', '湘潭九华示范区', '湘乡市', '韶山市'],
        常德: ['武陵区', '鼎城区', '安乡县', '汉寿县', '澧县', '临澧县', '桃源县', '石门县', '常德市西洞庭管理区', '津市市'],
        郴州: ['北湖区', '苏仙区', '桂阳县', '宜章县', '永兴县', '嘉禾县', '临武县', '汝城县', '桂东县', '安仁县', '资兴市'],
        大庆: ['萨尔图区', '龙凤区', '让胡路区', '红岗区', '大同区', '肇州县', '肇源县', '林甸县', '杜尔伯特蒙古族自治县', '大庆高新技术产业开发区'],
        齐齐哈尔: ['龙沙区', '建华区', '铁锋区', '昂昂溪区', '富拉尔基区', '碾子山区', '梅里斯达斡尔族区', '龙江县', '依安县', '泰来县', '甘南县', '富裕县', '克山县', '克东县', '拜泉县', '讷河市'],
        牡丹江: ['东安区', '阳明区', '爱民区', '西安区', '林口县', '绥芬河市', '海林市', '宁安市', '穆棱市', '东宁市'],
        鞍山: ['铁东区', '铁西区', '立山区', '千山区', '台安县', '岫岩满族自治县', '海城市'],
        锦州: ['古塔区', '凌河区', '太和区', '黑山县', '义县', '凌海市', '北镇市'],
        营口: ['站前区', '西市区', '鲅鱼圈区', '老边区', '盖州市', '大石桥市'],
        德州: ['德城区', '陵城区', '宁津县', '庆云县', '临邑县', '齐河县', '平原县', '夏津县', '武城县', '德州天衢新区', '乐陵市', '禹城市'],
        聊城: ['东昌府区', '茌平区', '阳谷县', '莘县', '东阿县', '冠县', '高唐县', '临清市'],
        威海: ['环翠区', '文登区', '威海火炬高技术产业开发区', '威海经济技术开发区', '威海临港经济技术开发区', '荣成市', '乳山市'],
        日照: ['东港区', '岚山区', '五莲县', '莒县', '日照经济技术开发区'],
        莆田: ['城厢区', '涵江区', '荔城区', '秀屿区', '仙游县'],
        漳州: ['芗城区', '龙文区', '龙海区', '长泰区', '云霄县', '漳浦县', '诏安县', '东山县', '南靖县', '平和县', '华安县'],
        龙岩: ['新罗区', '永定区', '长汀县', '上杭县', '武平县', '连城县', '漳平市'],
        九江: ['濂溪区', '浔阳区', '柴桑区', '武宁县', '修水县', '永修县', '德安县', '都昌县', '湖口县', '彭泽县', '瑞昌市', '共青城市', '庐山市'],
        上饶: ['信州区', '广丰区', '广信区', '玉山县', '铅山县', '横峰县', '弋阳县', '余干县', '鄱阳县', '万年县', '婺源县', '德兴市'],
        宜春: ['袁州区', '奉新县', '万载县', '上高县', '宜丰县', '靖安县', '铜鼓县', '丰城市', '樟树市', '高安市'],
        自贡: ['自流井区', '贡井区', '大安区', '沿滩区', '荣县', '富顺县'],
        泸州: ['江阳区', '纳溪区', '龙马潭区', '泸县', '合江县', '叙永县', '古蔺县'],
        德阳: ['旌阳区', '罗江区', '中江县', '广汉市', '什邡市', '绵竹市'],
        乐山: ['市中区', '沙湾区', '五通桥区', '金口河区', '犍为县', '井研县', '夹江县', '沐川县', '峨边彝族自治县', '马边彝族自治县', '峨眉山市'],
        曲靖: ['麒麟区', '沾益区', '马龙区', '陆良县', '师宗县', '罗平县', '富源县', '会泽县', '宣威市'],
        大理: ['大理市', '漾濞彝族自治县', '祥云县', '宾川县', '弥渡县', '南涧彝族自治县', '巍山彝族回族自治县', '永平县', '云龙县', '洱源县', '剑川县', '鹤庆县'],
    };
    const strategyAddressKeywordSuffixes = ['核心商圈', '交通枢纽', '产业园区', '医院周边', '高校周边', '文旅景区', '社区商业', '商务办公区', '会展中心周边'];
    const strategyLocationSuffixesByCityTier = {
        一线: ['核心商务区', '交通枢纽', '产业园区', '会展中心周边', '医院周边', '文旅景区'],
        二线: ['核心商圈', '高铁枢纽周边', '产业园区', '会展中心周边', '高校周边', '文旅景区'],
        三线: ['城市中心商圈', '高铁站周边', '产业园区', '医院周边', '高校周边', '文旅景区'],
        四线: ['城市中心商圈', '交通枢纽周边', '产业园区', '医院周边', '学校周边', '文旅景区'],
    };
    const strategyAddressKeywordOptionsByDistrict = {
        浦东新区: ['陆家嘴核心商务区', '张江高科产业园', '前滩商务区', '世纪公园周边', '浦东机场交通圈', '金桥开发区'],
        黄浦区: ['人民广场商圈', '南京东路商圈', '外滩文旅区', '新天地商务区', '豫园文旅区'],
        徐汇区: ['徐家汇商圈', '漕河泾开发区', '衡山路文旅区', '上海南站交通圈'],
        长宁区: ['虹桥商务区', '中山公园商圈', '古北商务区', '虹桥机场交通圈'],
        静安区: ['南京西路商圈', '静安寺商圈', '大宁商务区', '苏河湾文旅区'],
        普陀区: ['长风商务区', '真如副中心', '环球港商圈', '长寿路商圈'],
        虹口区: ['北外滩商务区', '四川北路商圈', '鲁迅公园周边'],
        杨浦区: ['五角场商圈', '大创智产业区', '大学路高校周边', '滨江文旅区'],
        闵行区: ['虹桥枢纽周边', '莘庄商圈', '七宝古镇文旅区', '紫竹高新区'],
        宝山区: ['宝山万达商圈', '吴淞邮轮港', '顾村公园周边'],
        嘉定区: ['嘉定新城商圈', '安亭汽车城', '南翔古镇文旅区'],
        松江区: ['松江大学城', '佘山文旅区', '松江新城商圈'],
        青浦区: ['国家会展中心周边', '朱家角文旅区', '青浦新城商圈'],
        奉贤区: ['南桥新城商圈', '奉贤海湾文旅区', '东方美谷产业区'],
        崇明区: ['陈家镇文旅区', '东滩湿地周边', '崇明新城商圈'],
        锦江区: ['春熙路太古里商圈', '东大街商务区', '锦江宾馆周边', '九眼桥文旅区'],
        青羊区: ['宽窄巷子文旅区', '天府广场商圈', '金沙遗址周边', '骡马市商务区'],
        金牛区: ['火车北站交通圈', '茶店子交通圈', '金牛万达商圈', '欢乐谷文旅区'],
        武侯区: ['桐梓林商务区', '科华路商圈', '武侯祠文旅区', '红牌楼商圈'],
        成华区: ['建设路商圈', '成都东站交通圈', '东郊记忆文旅区', '龙潭工业园'],
        高新区: ['金融城商务区', '软件园产业区', '世纪城会展周边', '天府三街商圈'],
        天河区: ['珠江新城商务区', '天河路商圈', '广州东站交通圈', '智慧城产业区'],
        南山区: ['科技园产业区', '后海总部基地', '深圳湾口岸周边', '华侨城文旅区'],
        西湖区: ['西湖景区周边', '黄龙商务区', '文教高校周边', '转塘文旅区'],
        江岸区: ['江汉路商圈', '武汉天地商务区', '汉口江滩文旅区', '二七商务区'],
    };
    const strategyCompetitorCountByTierGrade = {
        一线: { 经济型: 46, 中端精选: 58, 中高端商务: 36, 精品度假: 22 },
        二线: { 经济型: 34, 中端精选: 42, 中高端商务: 26, 精品度假: 16 },
        三线: { 经济型: 22, 中端精选: 28, 中高端商务: 16, 精品度假: 11 },
        四线: { 经济型: 14, 中端精选: 18, 中高端商务: 9, 精品度假: 7 },
    };
    const strategyCompetitorCityAdjustment = {
        北京: 6, 上海: 6, 广州: 5, 深圳: 5,
        成都: 4, 重庆: 4, 杭州: 4, 武汉: 3, 西安: 3, 苏州: 3, 南京: 3,
        青岛: 2, 厦门: 2, 三亚: 2, 珠海: 2,
    };
    const marketEvaluationDecorationOptions = ['经济型-基础改造', '经济型-标准翻新', '中端精选-轻改', '中端精选-标准', '中端精选-品质', '中高端商务-标准', '中高端商务-品质', '度假/亲子主题'];
    const marketEvaluationCustomerOptions = ['商务差旅', '会议会展', '休闲旅游', '亲子家庭', '医院陪护', '高校考培', '园区长住', '交通中转', '本地消费', '政企接待'];
    const marketEvaluationConditionFields = [
        { key: 'asset_type', label: '物业形态', options: ['整栋独立', '集中楼层', '裙楼改造', '园区配套', '商住混合'] },
        { key: 'operation_model', label: '经营模式', options: ['直营', '加盟', '托管', '联营'] },
        { key: 'contract_status', label: '合同状态', options: ['待谈判', '已锁定', '已签约', '需重谈'] },
        { key: 'lease_years', label: '租期（年）', type: 'number' },
        { key: 'rent_free_months', label: '免租期（月）', type: 'number' },
        { key: 'deposit_months', label: '押金（月）', type: 'number' },
        { key: 'transfer_fee', label: '转让费（万元）', type: 'number' },
        { key: 'fitout_budget', label: '装修预算（万元）', type: 'number' },
        { key: 'expected_adr', label: '目标ADR（元）', type: 'number' },
        { key: 'expected_occupancy_rate', label: '目标入住率（%）', type: 'number' },
        { key: 'competitor_count', label: '3公里竞品数', type: 'number' },
        { key: 'parking_spaces', label: '停车位', type: 'number' },
        { key: 'ota_market_penetration_rate', label: 'OTA平台市场渗透率（%）', type: 'number' }
    ];
    const marketEvaluationDefaults = {
        city_tier: '一线',
        city: '上海',
        business_area: '核心商务区',
        property_area: 2600,
        estimated_rent: 160000,
        target_room_count: 72,
        decoration_level: '中端精选-标准',
        primary_customer: '商务差旅',
        secondary_customer: '会议会展',
        target_customer: '商务差旅+会议会展',
        asset_type: '集中楼层',
        operation_model: '直营',
        contract_status: '待谈判',
        lease_years: 8,
        rent_free_months: 4,
        deposit_months: 3,
        transfer_fee: 0,
        fitout_budget: 360,
        expected_adr: 268,
        expected_occupancy_rate: 78,
        competitor_count: 12,
        parking_spaces: 20,
        ota_market_penetration_rate: 62
    };
    const marketEvaluationTierOfCity = (city) => marketEvaluationCityOptions.find(item => item.name === city)?.tier || '';
    const marketEvaluationCityOptionsForTier = (cityOptions = marketEvaluationCityOptions, tier = '') => (
        (Array.isArray(cityOptions) ? cityOptions : []).filter(item => item.tier === tier)
    );
    const secondaryMarketEvaluationCustomerOptions = (customerOptions = marketEvaluationCustomerOptions, primaryCustomer = '') => (
        (Array.isArray(customerOptions) ? customerOptions : []).filter(option => option !== primaryCustomer)
    );
    const splitMarketEvaluationCustomer = (value) => {
        const parts = String(value || '').split(/[+＋/、,，]/).map(item => item.trim()).filter(Boolean);
        return {
            primary: parts[0] || marketEvaluationDefaults.primary_customer,
            secondary: parts[1] || marketEvaluationDefaults.secondary_customer,
        };
    };
    const strategyDistrictOptionsForCity = (city) => {
        const options = strategyDistrictOptionsByCity[city];
        return Array.isArray(options) && options.length > 0 ? options : [city || '当前城市'];
    };
    const strategyAddressKeywordOptionsForLocation = (city, district, tier) => {
        if (strategyAddressKeywordOptionsByDistrict[district]) {
            return strategyAddressKeywordOptionsByDistrict[district];
        }
        const suffixes = strategyLocationSuffixesByCityTier[tier] || strategyAddressKeywordSuffixes;
        const area = district && district !== '市辖区' ? district : (city || '当前城市');
        return suffixes.slice(0, 6).map(suffix => `${area}${suffix}`);
    };
    const strategyKnownAddressSuffixes = Array.from(new Set([
        ...strategyAddressKeywordSuffixes,
        ...Object.values(strategyLocationSuffixesByCityTier).flat(),
    ]));
    const isKnownStrategyAddressKeyword = (keyword) => {
        if (!keyword) return false;
        return Object.values(strategyAddressKeywordOptionsByDistrict).some(items => items.includes(keyword))
            || strategyKnownAddressSuffixes.some(suffix => String(keyword).endsWith(suffix));
    };
    const strategyCityOptionsForProject = (project = {}) => {
        const tier = project.city_tier || marketEvaluationTierOfCity(project.city) || marketEvaluationDefaults.city_tier;
        const options = marketEvaluationCityOptions.filter(item => item.tier === tier);
        const isKnownCity = marketEvaluationCityOptions.some(item => item.name === project.city);
        if (project.city && !isKnownCity && !options.some(item => item.name === project.city)) {
            return [{ name: project.city, tier }, ...options];
        }
        return options;
    };
    const strategyDistrictOptionsForProject = (project = {}) => {
        const options = strategyDistrictOptionsForCity(project.city);
        if (project.district && !options.includes(project.district)) {
            return [project.district, ...options];
        }
        return options;
    };
    const strategyAddressKeywordOptionsForProject = (project = {}) => {
        const options = strategyAddressKeywordOptionsForLocation(project.city, project.district, project.city_tier);
        if (project.address && !options.includes(project.address)) {
            return [project.address, ...options];
        }
        return options;
    };
    const isKnownStrategyDistrict = (district) => Object.values(strategyDistrictOptionsByCity).some(items => items.includes(district));
    const strategyNextDistrictForProject = (project = {}) => {
        const options = strategyDistrictOptionsForCity(project.city);
        const shouldResetDistrict = !project.district
            || project.district === '市辖区'
            || (isKnownStrategyDistrict(project.district) && !options.includes(project.district));
        return options.length > 0 && shouldResetDistrict ? options[0] : project.district;
    };
    const strategyNextAddressForProject = (project = {}) => {
        const options = strategyAddressKeywordOptionsForLocation(project.city, project.district, project.city_tier);
        const shouldResetAddress = !project.address || isKnownStrategyAddressKeyword(project.address);
        return options.length > 0 && shouldResetAddress && !options.includes(project.address)
            ? options[0]
            : project.address;
    };
    const estimateStrategyCompetitorCount = (project = {}) => {
        const value = project.competitor_count;
        if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
            return null;
        }
        return Math.max(0, Number(value));
    };
    const normalizeMarketEvaluationForm = (input = {}) => {
        const form = { ...marketEvaluationDefaults, ...input };
        form.city_tier = marketEvaluationCityTierOptions.includes(input.city_tier)
            ? input.city_tier
            : (marketEvaluationTierOfCity(form.city) || marketEvaluationDefaults.city_tier);
        const cityOptions = marketEvaluationCityOptions.filter(item => item.tier === form.city_tier);
        if (!cityOptions.some(item => item.name === form.city)) {
            form.city = cityOptions[0]?.name || marketEvaluationDefaults.city;
        }
        if ((!form.primary_customer || !form.secondary_customer) && form.target_customer) {
            const customers = splitMarketEvaluationCustomer(form.target_customer);
            form.primary_customer = form.primary_customer || customers.primary;
            form.secondary_customer = form.secondary_customer || customers.secondary;
        }
        if (form.primary_customer === form.secondary_customer) {
            form.secondary_customer = marketEvaluationCustomerOptions.find(option => option !== form.primary_customer) || marketEvaluationDefaults.secondary_customer;
        }
        form.target_customer = [form.primary_customer, form.secondary_customer].filter(Boolean).join('+');
        if ((form.ota_market_penetration_rate === undefined || form.ota_market_penetration_rate === null || form.ota_market_penetration_rate === '') && input.ota_platform_market_penetration_rate !== undefined) {
            form.ota_market_penetration_rate = input.ota_platform_market_penetration_rate;
        }
        return form;
    };
    const toNumber = (value, fallback = 0) => {
        const num = Number(value);
        return Number.isFinite(num) ? num : fallback;
    };
    const toDisplayNumber = (value) => {
        if (value === null || value === undefined || value === '') return null;
        const num = Number(value);
        return Number.isFinite(num) ? num : null;
    };
    const aiRound = (value, digits = 0) => {
        const num = toDisplayNumber(value);
        return num === null ? '--' : Number(num.toFixed(digits));
    };
    const formatCurrency = (value) => {
        const num = toDisplayNumber(value);
        return num === null ? '--' : `¥${Math.round(num).toLocaleString()}`;
    };
    const formatPercent = (value) => {
        const num = toDisplayNumber(value);
        return num === null ? '--' : `${aiRound(num * 100, 1)}%`;
    };
    const formatFeasibilityPayback = (value) => value === null || value === undefined || value === ''
        ? '--'
        : `${aiRound(value, 1)}个月`;
    const buildFeasibilityInputCards = ({ project = {}, simulationParams = {} } = {}) => {
        const rooms = toDisplayNumber(project.room_count);
        const area = toDisplayNumber(project.property_area);
        const rent = toDisplayNumber(project.monthly_rent);
        const decoration = toDisplayNumber(project.decoration_budget);
        const transfer = toDisplayNumber(project.transfer_fee);
        const budgetObserved = decoration !== null && transfer !== null;
        const budget = budgetObserved ? decoration + transfer : null;
        const perRoomArea = rooms !== null && rooms > 0 && area !== null && area > 0 ? `${aiRound(area / rooms, 1)}㎡/间` : '面积待补全';
        const rentPerRoom = rooms !== null && rooms > 0 && rent !== null ? `${formatCurrency(rent / rooms)}/间/月` : '租金待补全';
        const investmentPerRoom = rooms !== null && rooms > 0 && budget !== null
            ? `${formatCurrency((decoration + transfer) / rooms)}/间`
            : '预算待补全';

        return [
            { label: '项目规模', value: rooms !== null && rooms > 0 ? `${rooms}间` : '--', meta: perRoomArea, truthKeys: ['room_count', 'property_area'] },
            { label: '月租金', value: rent === null ? '--' : formatCurrency(rent), meta: rentPerRoom, truthKeys: ['monthly_rent'] },
            { label: '装转预算', value: budget === null ? '--' : formatCurrency(budget), meta: investmentPerRoom, truthKeys: ['decoration_budget', 'transfer_fee'] },
            { label: '智算参数', value: `${formatCurrency(simulationParams.adr)} / ${aiRound(simulationParams.occupancyRate, 1)}%`, meta: 'ADR / OCC', truthKeys: ['adr', 'occ'] },
        ];
    };
    const buildFeasibilityReportCards = (report = null) => {
        if (!report) return [];
        const summary = report.summary || {};
        const market = report.market_judgement || {};
        return [
            { label: '总投资', value: formatCurrency(summary.total_investment), meta: '含装修与筹开投入', metricKey: 'summary.total_investment' },
            { label: '回本周期', value: formatFeasibilityPayback(summary.payback_months), meta: '模型现金流测算', metricKey: 'summary.payback_months' },
            { label: '市场评分', value: market.market_score ?? '--', meta: '智略市场判断', metricKey: 'market_judgement.market_score' },
            { label: '竞争强度', value: market.competition_level || '--', meta: market.recommended_model || '定位待判断', metricKey: 'market_judgement.competition_level' },
        ];
    };
    const buildFeasibilityAiEmpowerment = (report = null) => {
        const safeReport = report || {};
        const market = safeReport.market_judgement || {};
        const actions = Array.isArray(safeReport.action_plan) ? safeReport.action_plan : [];
        const risks = Array.isArray(safeReport.risk_list) ? safeReport.risk_list : [];
        const evidence = Array.isArray(safeReport.evidence) ? safeReport.evidence : [];
        const assumptions = Array.isArray(safeReport.assumptions) ? safeReport.assumptions : [];
        const source = String(safeReport.ai_evaluation?.source || safeReport.report_source || safeReport.source || '').trim().toLowerCase();
        const isFallback = source === 'fallback'
            || assumptions.some(item => String(item || '').includes('LLM报告生成失败') || String(item || '').includes('本地测算兜底'));
        const isAiGenerated = source === 'llm' || source === 'ai';
        const hasGeneratedReport = Boolean(
            safeReport.conclusion_grade
            || safeReport.conclusion_text
            || safeReport.core_reason
            || actions.length
            || risks.length
            || evidence.length
        );
        const firstAction = actions[0] || {};
        const firstRisk = risks[0] || {};
        const sourceText = evidence.map(item => item.source || item.title).filter(Boolean).slice(0, 3).join('、');

        return {
            isFallback,
            sourceLabel: isFallback
                ? '本地测算兜底（非AI）'
                : (isAiGenerated ? 'AI模型已生成' : (hasGeneratedReport ? '报告来源未核验' : '尚未生成')),
            headline: `${safeReport.conclusion_grade || '-'}级 · ${safeReport.conclusion_text || '等待生成结论'}`,
            conclusion: safeReport.core_reason || '生成后将汇总项目输入、智略判断、智算结果和风险动作。',
            nextAction: firstAction.title ? `${firstAction.title}：${firstAction.detail || '-'}` : '生成后输出首要执行动作',
            evidenceText: sourceText || '用户输入、本地测算与系统数据快照',
            cards: [
                {
                    label: '产品定位',
                    value: market.recommended_model || '--',
                    meta: market.target_customer || '目标客群待确认',
                },
                {
                    label: '市场判断',
                    value: market.market_score !== undefined && market.market_score !== null ? `${market.market_score}分` : '--',
                    meta: market.competition_level || '竞争强度待判断',
                },
                {
                    label: '首要动作',
                    value: firstAction.priority || 'P0',
                    meta: firstAction.title || '复核核心假设',
                },
                {
                    label: '主要风险',
                    value: firstRisk.level ? `${firstRisk.level}风险` : '--',
                    meta: firstRisk.risk || '风险清单待生成',
                },
            ],
        };
    };
    const buildFeasibilityPayload = ({
        project = {},
        simulationParams = {},
    } = {}) => ({
        project_name: project.project_name,
        city: project.city,
        district: project.district,
        address: project.address,
        property_area: project.property_area,
        room_count: project.room_count,
        monthly_rent: project.monthly_rent,
        lease_years: project.lease_years,
        decoration_budget: project.decoration_budget,
        transfer_fee: project.transfer_fee || 0,
        target_brand_level: project.target_brand_level || project.target_grade,
        target_customer: project.target_customer || project.primary_customer,
        notes: project.notes || '',
        adr: simulationParams.adr,
        occ: simulationParams.occupancyRate,
    });

    const feasibilityDecisionClassForGrade = (grade) => {
        const normalized = String(grade || '').toUpperCase();
        if (normalized === 'A') return 'feasibility-grade-a';
        if (normalized === 'B') return 'feasibility-grade-b';
        if (normalized === 'C') return 'feasibility-grade-c';
        if (normalized === 'D') return 'feasibility-grade-d';
        return 'feasibility-grade-neutral';
    };
    const stringifyFeasibilityReport = (report = null, projectName = '') => {
        if (!report) return '';
        const scenarios = (report.financial_scenarios || []).map(row =>
            `${row.name}: ADR ${formatCurrency(row.adr)}, OCC ${formatPercent(row.occ)}, 月收入 ${formatCurrency(row.monthly_revenue)}, 月净现金流 ${formatCurrency(row.monthly_net_cashflow)}, 回本 ${row.payback_months ?? '不可回本'}个月`
        ).join('\n');
        const risks = (report.risk_list || []).map(item => `${item.level}风险｜${item.risk}: ${item.reason}；应对：${item.action}`).join('\n');
        const actions = (report.action_plan || []).map(item => `${item.priority} ${item.title}: ${item.detail}`).join('\n');
        return [
            `${report.summary?.project_name || projectName}可行性报告`,
            `结论: ${report.conclusion_grade}级｜${report.conclusion_text}`,
            `核心理由: ${report.core_reason}`,
            `项目摘要: ${report.summary?.location || ''}，${report.summary?.room_count || 0}间，总投资${formatCurrency(report.summary?.total_investment || 0)}，回本${report.summary?.payback_months || '不可回本'}个月`,
            `市场判断: ${report.market_judgement?.reasoning || ''}`,
            `三情景模拟:\n${scenarios}`,
            `风险清单:\n${risks}`,
            `行动计划:\n${actions}`,
            `假设:\n${(report.assumptions || []).join('\n')}`,
        ].join('\n\n');
    };

    const normalizeMarketRiskSeverity = (value, index = 0) => {
        const severity = String(value || '').trim().toUpperCase();
        if (['P0', 'P1', 'P2'].includes(severity)) return severity;
        return index === 0 ? 'P0' : 'P1';
    };
    const inferMarketEvaluationRiskDetail = (item = {}, result = {}, form = {}, index = 0) => {
        const metric = String(item.metric || '风险指标').trim() || '风险指标';
        const threshold = String(item.threshold || '').trim();
        const action = String(item.action || '').trim() || '补充数据后复核。';
        const metricText = `${metric}${threshold}`;
        const expectedAdr = Number(form.expected_adr || 0);
        const expectedOcc = Number(form.expected_occupancy_rate || 0);
        const competitorCount = Number(form.competitor_count || 0);
        const otaRate = Number(form.ota_market_penetration_rate || 0);
        const rentPerRoom = Number(result.metrics?.rent_per_room || 0);
        const rentPerSquare = Number(result.metrics?.rent_per_square || 0);

        let evidence = String(item.evidence || item.reason || '').trim();
        let impact = String(item.impact || '').trim();
        let validation = String(item.validation || item.verification || item.check_method || '').trim();
        let owner = String(item.owner || '').trim();
        let deadline = String(item.deadline || item.timing || '').trim();

        if (!evidence) {
            if (/ADR|价格|价带/.test(metricText)) {
                evidence = `当前目标ADR为${expectedAdr || '-'}元，需要用3公里同档竞品可订价、评分和点评量复核。`;
            } else if (/入住|出租/.test(metricText)) {
                evidence = `当前目标入住率为${expectedOcc || '-'}%，需验证商圈工作日、周末和淡旺季需求是否支撑。`;
            } else if (/租金|坪效|现金流/.test(metricText)) {
                evidence = `当前单房月租约${rentPerRoom || '-'}元、租金坪效约${rentPerSquare || '-'}元/㎡，会直接影响回本周期。`;
            } else if (/OTA|转化|流量/.test(metricText)) {
                evidence = `当前OTA平台市场渗透率为${otaRate || '-'}%，仍需拆分曝光、访客、转化和客单价验证。`;
            } else if (/竞品|供给/.test(metricText)) {
                evidence = `当前录入3公里竞品数为${competitorCount || '-'}家，需核对同档、同价带和同客群样本。`;
            } else {
                evidence = '当前输入仍缺少真实市场、竞品或OTA复核数据，该项需要在投决前补齐证据。';
            }
        }
        if (!impact) {
            if (/ADR|价格|价带/.test(metricText)) {
                impact = '若目标价带高于真实竞品承接能力，开业期会出现降价换量，收入预测和回本周期偏乐观。';
            } else if (/入住|出租/.test(metricText)) {
                impact = '若入住率假设偏高，现金流安全边际会被放大，淡季或爬坡期容易低于预算。';
            } else if (/租金|坪效|现金流/.test(metricText)) {
                impact = '若租金条款没有调整空间，免租期和装修投入稍有偏差就会拉长投资回收周期。';
            } else if (/OTA|转化|流量/.test(metricText)) {
                impact = '若线上获客基础不足，后续需要依赖促销和投流补量，利润率会被压缩。';
            } else {
                impact = '该风险会影响价格带、收入爬坡、现金流或最终投决结论，不能只按当前初筛结果推进。';
            }
        }
        if (!validation) {
            if (/ADR|价格|价带/.test(metricText)) {
                validation = '采集不少于8家同档竞品近30天可订价、满房日、评分、点评量，并重算保守/基准/乐观ADR。';
            } else if (/入住|出租/.test(metricText)) {
                validation = '按工作日、周末、节假日分别复核竞品入住率和商圈客源，更新三情景现金流。';
            } else if (/租金|坪效|现金流/.test(metricText)) {
                validation = '把租金、免租期、押金、装修投入拆成敏感性测算，明确最低可接受租金条款。';
            } else if (/OTA|转化|流量/.test(metricText)) {
                validation = '补齐OTA曝光、访客、转化率、间夜和评价质量，判断自然流量能否支撑开业爬坡。';
            } else {
                validation = '补齐真实样本、复算关键指标，并在投决会前形成可追溯的数据附件。';
            }
        }
        if (!owner) {
            if (/ADR|价格|价带/.test(metricText)) owner = '收益管理/投资拓展';
            else if (/OTA|转化|流量/.test(metricText)) owner = '渠道运营/收益管理';
            else if (/租金|坪效|现金流/.test(metricText)) owner = '投资测算/拓展谈判';
            else owner = '投资拓展/运营负责人';
        }
        if (!deadline) {
            deadline = index === 0 ? '投决会前' : '合同条款锁定前';
        }

        return {
            metric,
            threshold,
            action,
            severity: normalizeMarketRiskSeverity(item.severity || item.priority, index),
            evidence,
            impact,
            validation,
            owner,
            deadline
        };
    };
    const buildMarketEvaluationAiRiskSuggestions = ({ result = {}, form = {} } = {}) => {
        const watchPoints = Array.isArray(result.ai_evaluation?.watch_points)
            ? result.ai_evaluation.watch_points
            : [];
        if (watchPoints.length) {
            return watchPoints
                .map((item, index) => inferMarketEvaluationRiskDetail(item, result, form, index))
                .filter(item => item.metric || item.threshold || item.action);
        }
        const risks = Array.isArray(result.not_recommended_risks) ? result.not_recommended_risks : [];
        return risks.map((item, index) => inferMarketEvaluationRiskDetail({
            metric: '风险点',
            threshold: String(item || '').trim(),
            action: '补充真实竞品、客流和 OTA 数据后复核。'
        }, result, form, index)).filter(item => item.threshold);
    };
    const marketEvaluationRiskSeverityClass = (severity) => {
        if (severity === 'P0') return 'bg-red-50 text-red-700 border-red-100';
        if (severity === 'P1') return 'bg-amber-50 text-amber-700 border-amber-100';
        return 'bg-gray-50 text-gray-600 border-gray-100';
    };
    const formatMarketEvaluationScoreChange = (value) => {
        const score = toDisplayNumber(value);
        if (score === null) return '未返回';
        if (score > 0) return `+${score}`;
        return String(score);
    };
    const marketEvaluationScoreChangeClass = (value) => {
        const score = toDisplayNumber(value);
        if (score === null) return 'bg-gray-50 text-gray-600 border-gray-100';
        if (score > 0) return 'bg-emerald-50 text-emerald-700 border-emerald-100';
        if (score < 0) return 'bg-red-50 text-red-700 border-red-100';
        return 'bg-gray-50 text-gray-600 border-gray-100';
    };
    const normalizeAiRecommendationDisplay = (item) => {
        if (item && typeof item === 'object') {
            const title = String(item.title || '').trim();
            const detail = String(item.detail || item.content || '').trim();
            return {
                priority: String(item.priority || 'P1').trim() || 'P1',
                title: title || 'AI建议',
                detail: detail || title || '待补充建议'
            };
        }
        return {
            priority: 'P1',
            title: 'AI建议',
            detail: String(item || '').trim()
        };
    };
    const buildMarketEvaluationAiJudgementRows = (result = {}) => {
        const judgement = result.ai_evaluation?.market_judgement || {};
        return [
            {
                label: '供给竞争强度',
                value: judgement.supply_competition_strength || result.supply_competition_strength || '待补充竞品数据',
            },
            {
                label: '价格带建议',
                value: judgement.price_band_suggestion || result.price_band_suggestion || '待补充价格带数据',
            },
            {
                label: '建议动作',
                value: judgement.decision || result.ai_evaluation?.decision || result.decision || '待复核',
            },
        ];
    };
    const buildMarketEvaluationAiRecommendations = (result = {}) => {
        const aiRecommendations = Array.isArray(result.ai_evaluation?.recommendations)
            ? result.ai_evaluation.recommendations
            : [];
        const source = aiRecommendations.length
            ? aiRecommendations
            : (Array.isArray(result.ai_operation_suggestions) ? result.ai_operation_suggestions : []);
        return source.map(normalizeAiRecommendationDisplay).filter(item => item.detail);
    };
    const buildMarketEvaluationAiAssumptions = (result = {}) => {
        const assumptions = Array.isArray(result.ai_evaluation?.assumptions)
            ? result.ai_evaluation.assumptions
            : [];
        return assumptions
            .map(item => String(item || '').trim())
            .filter(item => item && !/规则引擎|rule engine/i.test(item));
    };
    const buildMarketEvaluationScoreFormula = (result = {}) => {
        const formula = result.market_heat_score_formula || {};
        const finalScore = toDisplayNumber(result.market_heat_score);
        return {
            base_score: toDisplayNumber(formula.base_score) ?? (finalScore === null ? null : 62),
            raw_score: toDisplayNumber(formula.raw_score) ?? finalScore,
            final_score: toDisplayNumber(formula.final_score) ?? finalScore,
            cap_rule: String(formula.cap_rule || (finalScore === null ? '评分口径未返回' : '0-100封顶/保底'))
        };
    };
    const buildMarketEvaluationScoreBreakdown = (result = {}) => {
        const rows = Array.isArray(result.market_heat_score_breakdown)
            ? result.market_heat_score_breakdown
            : [];
        if (rows.length) {
            return rows.map((item, index) => ({
                label: String(item.label || `评分项${index + 1}`).trim(),
                score_change: toDisplayNumber(item.score_change ?? item.delta),
                raw_score_after: toDisplayNumber(item.raw_score_after),
                reason: String(item.reason || '按当前输入参与市场热度评分。').trim()
            })).filter(item => item.label && item.reason);
        }
        const finalScore = toDisplayNumber(result.market_heat_score);
        if (finalScore === null) return [];
        return [{
            label: '历史评分',
            score_change: finalScore,
            raw_score_after: finalScore,
            reason: '历史记录未保存评分明细，仅保留市场热度总分。'
        }];
    };
    const buildMarketEvaluationScorePercent = (formula = {}) => {
        const finalScore = Number(formula.final_score);
        return Math.max(0, Math.min(100, Number.isFinite(finalScore) ? finalScore : 0));
    };
    const buildMarketEvaluationAiRiskNote = (result = {}) => {
        const assumptions = buildMarketEvaluationAiAssumptions(result);
        if (assumptions.length) {
            return `AI复核假设：${assumptions.join('；')}`;
        }
        if (result.ai_evaluation?.source === 'llm') {
            return 'AI风险建议基于当前输入和市场评估结果生成，需用真实市场、竞品和 OTA 数据复核。';
        }
        if (result.ai_evaluation?.source === 'fallback') {
            return 'AI模型不可用，当前使用本地兜底风险建议。';
        }
        return String(result.data_status?.notice || '').trim();
    };
    const benchmarkModelAiSourceLabelForResult = (result = {}) => {
        const source = result.ai_evaluation?.source;
        if (source === 'llm') return 'AI模型评估';
        if (source === 'fallback') return '本地兜底';
        return '来源未核验';
    };
    const buildBenchmarkModelAiRecommendations = (result = {}) => {
        const recommendations = result.ai_evaluation?.recommendations;
        return Array.isArray(recommendations)
            ? recommendations.map(normalizeAiRecommendationDisplay).filter(item => item.detail)
            : [];
    };
    const buildBenchmarkModelAiWatchPoints = (result = {}) => {
        const watchPoints = result.ai_evaluation?.watch_points;
        if (!Array.isArray(watchPoints)) return [];
        return watchPoints.map(item => ({
            metric: String(item?.metric || '关注指标').trim() || '关注指标',
            threshold: String(item?.threshold || '').trim(),
            action: String(item?.action || '').trim() || '补充真实竞品和 OTA 数据后复核。'
        })).filter(item => item.metric || item.threshold || item.action);
    };
    const buildBenchmarkModelAiAssumptionNote = (result = {}) => {
        const assumptions = result.ai_evaluation?.assumptions;
        if (!Array.isArray(assumptions)) return '';
        const items = assumptions.map(item => String(item || '').trim()).filter(Boolean);
        return items.length ? `AI复核假设：${items.join('；')}` : '';
    };
    const benchmarkModelDataNoticeForResult = (result = {}) => {
        if (result.ai_evaluation?.source === 'llm') {
            return 'AI赋能结果基于当前输入、竞品细化数据和标杆模型生成，需用真实竞品与 OTA 数据复核。';
        }
        if (result.ai_evaluation?.source === 'fallback') {
            return 'AI模型不可用，当前使用本地兜底生成标杆建议，需接入真实竞品与 OTA 数据复核。';
        }
        return String(result.data_status?.notice || '').trim();
    };
    const buildBenchmarkModelAiOutcomeCards = ({
        result = {},
        recommendations = [],
        watchPoints = [],
        assumptionNote = '',
        dataNotice = '',
        detailCompleteness = '',
    } = {}) => {
        const evaluation = result.ai_evaluation || {};
        const judgement = evaluation.model_judgement || {};
        const bestBenchmark = Array.isArray(result.recommended_benchmarks) ? result.recommended_benchmarks[0] : null;
        const bestModel = String(judgement.best_fit_model || bestBenchmark?.name || '').trim() || '--';
        const firstRecommendation = recommendations[0] || {};
        const firstWatchPoint = watchPoints[0] || {};
        const assumptionText = String(assumptionNote || '').replace(/^AI复核假设：/, '');
        return [
            {
                label: '选模结论',
                value: evaluation.decision || '等待AI结论',
                hint: bestModel !== '--' ? `优先标杆：${bestModel}` : '生成后输出优先标杆',
                icon: 'fas fa-bullseye'
            },
            {
                label: '复制策略',
                value: judgement.copy_priority || firstRecommendation.detail || '先复制高匹配标杆做法',
                hint: firstRecommendation.title || '房型、价格、渠道优先',
                icon: 'fas fa-copy'
            },
            {
                label: '风险监控',
                value: firstWatchPoint.metric || '真实竞品价格带',
                hint: firstWatchPoint.threshold || firstWatchPoint.action || '补齐竞品ADR、评分、点评量',
                icon: 'fas fa-shield-halved'
            },
            {
                label: '数据复核',
                value: dataNotice || detailCompleteness,
                hint: assumptionText || '生成后用 OTA 转化与点评文本复核',
                icon: 'fas fa-clipboard-check'
            }
        ];
    };

    const normalizeStrategyAiEvaluation = (raw) => {
        if (!raw || typeof raw !== 'object') return null;
        const toList = (value) => {
            if (Array.isArray(value)) {
                return value.map(item => {
                    if (item && typeof item === 'object') {
                        return item.detail || item.title || item.summary || '';
                    }
                    return String(item || '');
                }).map(item => item.trim()).filter(Boolean);
            }
            if (typeof value === 'string') {
                return value.split(/[\n\r;；、]+/).map(item => item.trim()).filter(Boolean);
            }
            return [];
        };
        const evaluation = {
            source: String(raw.source || '').trim(),
            model_key: String(raw.model_key || raw.modelKey || '').trim(),
            generated_at: String(raw.generated_at || raw.generatedAt || '').trim(),
            summary: String(raw.summary || '').trim(),
            decision: String(raw.decision || '').trim(),
            recommended_model: String(raw.recommended_model || raw.recommendedModel || '').trim(),
            target_customer: String(raw.target_customer || raw.targetCustomer || '').trim(),
            competition_pressure: String(raw.competition_pressure || raw.competitionPressure || '').trim(),
            decision_direction: String(raw.decision_direction || raw.decisionDirection || '').trim(),
            key_actions: toList(raw.key_actions || raw.keyActions),
            main_risks: toList(raw.main_risks || raw.mainRisks),
            next_data_to_verify: toList(raw.next_data_to_verify || raw.nextDataToVerify),
            assumptions: toList(raw.assumptions),
            error: String(raw.error || '').trim()
        };
        return (evaluation.summary || evaluation.decision || evaluation.key_actions.length || evaluation.main_risks.length || evaluation.assumptions.length) ? evaluation : null;
    };

    const normalizeStrategyResult = (data) => {
        const scores = data?.scores || {};
        const recommendation = data?.recommendation || {};
        const aiEvaluation = normalizeStrategyAiEvaluation(recommendation.ai_evaluation || data?.ai_evaluation);
        return {
            ...data,
            ai_evaluation: aiEvaluation,
            market_score: toDisplayNumber(scores.market_demand?.score),
            competition_score: toDisplayNumber(scores.competition?.score),
            property_score: toDisplayNumber(scores.property_fit?.score),
            cost_score: toDisplayNumber(scores.cost_pressure?.score),
            exit_score: toDisplayNumber(scores.exit_safety?.score),
            recommended_model: data?.decision_ready === false ? (recommendation.recommended_model || '') : (aiEvaluation?.recommended_model || recommendation.recommended_model || ''),
            target_customer: data?.decision_ready === false ? (recommendation.target_customer || '') : (aiEvaluation?.target_customer || recommendation.target_customer || ''),
            competition_pressure: data?.decision_ready === false ? (recommendation.competition_pressure || '待评估') : (aiEvaluation?.competition_pressure || recommendation.competition_pressure || ''),
            decision_direction: data?.decision_ready === false ? (recommendation.decision_direction || '补证后再决策') : (aiEvaluation?.decision_direction || recommendation.decision_direction || '智略定方向'),
            strategy_conclusion: data?.decision_ready === false ? (data?.decision || recommendation.decision || '数据不足') : (aiEvaluation?.decision || data?.decision || recommendation.decision || '')
        };
    };

    const buildStrategyPayload = (project = {}) => {
        const optionalNumber = (value) => value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
            ? null
            : Number(value);
        return ({
        project_name: project.project_name,
        city_tier: project.city_tier,
        city: project.city,
        district: project.district,
        address: project.address,
        property_area: toNumber(project.property_area),
        room_count: toNumber(project.room_count),
        monthly_rent: toNumber(project.monthly_rent),
        decoration_budget: toNumber(project.decoration_budget),
        lease_years: optionalNumber(project.lease_years),
        rent_free_months: optionalNumber(project.rent_free_months),
        business_type: project.business_type,
        target_customer: project.primary_customer,
        competitor_count: optionalNumber(project.competitor_count),
        target_hotel_level: project.target_grade,
        });
    };

    const buildStrategyScoreCards = (result = null) => {
        if (!result) return [];
        const scores = result.scores || {};
        const card = (label, value, score = {}) => ({
            label,
            value: value ?? '未返回',
            level: score.level || '待核验',
            reason: score.reasons?.[0] || (value === null || value === undefined ? '评分未返回' : '评分解释未返回'),
        });
        return [
            card('市场需求', result.market_score, scores.market_demand),
            card('竞争环境', result.competition_score, scores.competition),
            card('物业适配', result.property_score, scores.property_fit),
            card('成本压力', result.cost_score, scores.cost_pressure),
            card('退出安全', result.exit_score, scores.exit_safety)
        ];
    };
    const strategyFreshnessLabelForSnapshot = (snapshot = null) => {
        if (!snapshot) return '数据来源未加载';
        if (snapshot.ai_data_used === true) return 'AI数据参与';
        if (snapshot.freshness === 'realtime') return '实时数据';
        if (snapshot.freshness === 'today_cache') return '今日缓存';
        if (snapshot.local_data_used === true) return '同城授权样本';
        if (snapshot.external_data_available === false) return '外部数据未接入';
        return '数据新鲜度未返回';
    };
    const strategyAiSourceLabelForResult = (result = null) => {
        const source = result?.ai_evaluation?.source;
        if (source === 'llm') return 'AI模型评估';
        if (source === 'fallback') return '本地兜底';
        return '来源未核验';
    };
    const strategyAiModelDisplayLabelForSnapshot = (snapshot = {}) => {
        if (snapshot.ai_model_label) return snapshot.ai_model_label;
        const modelKey = String(snapshot.ai_model_key || '').trim();
        if (!modelKey) return '模型未返回';
        if (modelKey.includes('deepseek') && modelKey.includes('mimo')) return 'DeepSeek + MIMO';
        if (modelKey.includes('deepseek')) return 'DeepSeek';
        if (modelKey.includes('mimo')) return 'MIMO';
        return modelKey;
    };
    const strategyPoiDataSourceLabelForSnapshot = (snapshot = {}, modelLabel = '模型未返回') => {
        if (snapshot.external_data_used) return '已接入';
        if (snapshot.ai_search_used) {
            const provider = snapshot.ai_search_provider || (modelLabel !== '模型未返回' ? modelLabel : '提供方未返回');
            return `AI搜索（${provider}）`;
        }
        if (snapshot.ai_search_available) return 'AI搜索未完成';
        return '未接入';
    };
    const strategyDataNoticeForSnapshot = (snapshot = null, modelLabel = '模型未返回') => {
        if (!snapshot) return '';
        if (snapshot.ai_data_used && snapshot.ai_search_used && !snapshot.external_data_used) {
            const sourceText = modelLabel === '模型未返回' ? 'AI搜索（模型未返回）' : modelLabel;
            return `${sourceText} 已用于搜索补位；外部地图/POI接口未接入，周边客流和竞品仍需地图或实地复核。`;
        }
        if (snapshot.ai_data_used && !snapshot.external_data_used) {
            return 'AI模型已接入；外部地图/POI未接入，周边客流和竞品仍需补充验证。';
        }
        if (!snapshot.ai_data_used && !snapshot.external_data_used) {
            return 'AI模型未生成且外部地图/POI未接入；当前仅基于已录入条件与可用同城授权样本做规则情景推演。';
        }
        if (!snapshot.ai_data_used) {
            return 'AI模型未生成，当前保留本地规则推演。';
        }
        return '';
    };
    const buildStrategyDataSourceRows = (snapshot = {}, {
        modelLabel = '模型未返回',
        poiDataSourceLabel = '未接入',
    } = {}) => {
        const localData = snapshot.local_data || {};
        const externalData = snapshot.external_data || {};
        const localSources = Array.isArray(localData.data_sources) ? localData.data_sources.filter(Boolean) : [];
        const missing = [
            ...((Array.isArray(localData.missing_data) ? localData.missing_data : [])),
            ...((Array.isArray(externalData.missing_data) ? externalData.missing_data : []))
        ].filter(Boolean);
        const uniqueMissing = Array.from(new Set(missing));
        const missingValue = snapshot.ai_search_used && uniqueMissing.some(item => ['AMAP_KEY', 'BAIDU_MAP_KEY'].includes(item))
            ? '地图API未配置；AI搜索已补位，需地图/实地复核'
            : (uniqueMissing.join('、') || '无');
        return [
            { label: '同城授权样本', value: snapshot.local_data_used ? (localSources.join('、') || '已返回') : '未返回' },
            { label: 'AI模型', value: snapshot.ai_data_used ? `已接入（${modelLabel}）` : '未生成' },
            { label: '地图/POI', value: poiDataSourceLabel },
            { label: '缺失项', value: missingValue }
        ];
    };
    const buildStrategyAiEmpowermentCards = (result = null, {
        dataSourceRows = [],
        freshnessLabel = '数据新鲜度未返回',
        poiDataSourceLabel = '未接入',
        dataNotice = '',
    } = {}) => {
        if (!result) return [];
        const evaluation = result.ai_evaluation || {};
        const actions = Array.isArray(evaluation.key_actions) ? evaluation.key_actions : [];
        const risks = Array.isArray(evaluation.main_risks) ? evaluation.main_risks : [];
        const verifyItems = Array.isArray(evaluation.next_data_to_verify) ? evaluation.next_data_to_verify : [];
        const snapshot = result.data_snapshot || {};
        const evidenceCount = dataSourceRows.filter(row => {
            const value = String(row.value || '').trim();
            return value && !['未命中', '未生成', '未接入', '无'].includes(value);
        }).length;
        const firstAction = actions[0] || result.decision_direction || '先完成项目参数复核';
        const firstRisk = risks[0] || result.risk_level || result.competition_pressure || '等待风险复核';
        const firstVerify = verifyItems[0] || (snapshot.external_data_used ? '复核真实 OTA 转化与竞品点评' : '补充真实竞品、客流和 OTA 数据');
        return [
            {
                label: '建议动作',
                value: firstAction,
                hint: actions.length > 1 ? `另有 ${actions.length - 1} 项建议待人工审核` : '模型或规则建议，不代表已执行',
                icon: 'fas fa-list-check'
            },
            {
                label: '主要风险',
                value: firstRisk,
                hint: risks.length > 1 ? `另有 ${risks.length - 1} 项风险需跟踪` : (result.competition_pressure || '结合评分项持续观察'),
                icon: 'fas fa-triangle-exclamation'
            },
            {
                label: '证据覆盖',
                value: `${evidenceCount}/4`,
                hint: `${freshnessLabel} · ${poiDataSourceLabel}`,
                icon: 'fas fa-database'
            },
            {
                label: '复核要求',
                value: firstVerify,
                hint: verifyItems.length > 1 ? `另需复核 ${verifyItems.length - 1} 项数据` : (dataNotice || '进入可研报告前复核关键假设'),
                icon: 'fas fa-clipboard-check'
            }
        ];
    };

    const expansionRecordTypeLabel = (type) => ({
        market: '市场评估',
        benchmark: '标杆选模',
        collaboration: '协同提效',
    }[type] || type || '--');

    function readinessBadgeClass(stage, readyStages, warningStages, dangerStages = []) {
        if (readyStages.includes(stage)) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        if (warningStages.includes(stage)) return 'bg-amber-50 text-amber-700 border-amber-200';
        if (dangerStages.includes(stage)) return 'bg-rose-50 text-rose-700 border-rose-200';
        return 'bg-gray-50 text-gray-600 border-gray-200';
    }

    function readinessMissingText(readiness, emptyText) {
        const missing = Array.isArray(readiness?.missing_evidence) ? readiness.missing_evidence : [];
        if (!missing.length) return emptyText;
        return `缺口：${missing.slice(0, 3).map(item => item.label || item.code).join('、')}`;
    }

    function expansionReadinessBadgeClass(stage) {
        return readinessBadgeClass(
            stage,
            ['project_ready', 'review_ready'],
            ['approved_pending_tracking', 'diligence_required', 'partial_screening'],
            ['risk_recheck_required']
        );
    }

    function expansionReadinessMissingText(readiness) {
        return readinessMissingText(readiness, '暂无显式缺口；立项后仍需关联执行和投后跟踪证据。');
    }

    function feasibilityReadinessBadgeClass(stage) {
        return readinessBadgeClass(
            stage,
            ['feasibility_ready', 'review_ready'],
            ['approved_pending_tracking', 'diligence_required', 'manual_input_only', 'partial_report'],
            ['data_recheck_required']
        );
    }

    function feasibilityReadinessMissingText(readiness) {
        return readinessMissingText(readiness, '暂无显式缺口；可研后仍需保留审批、执行和投后跟踪证据。');
    }

    function executionIntentIdFromRecord(record) {
        const result = record?.result || {};
        const direct = Number(record?.execution_intent_id || result.operation_execution_intent_id || result.execution_intent_id || 0);
        if (direct > 0) return direct;
        const tracking = result.execution_tracking;
        const rows = Array.isArray(tracking) ? tracking : (tracking && typeof tracking === 'object' ? [tracking] : []);
        for (let i = rows.length - 1; i >= 0; i -= 1) {
            const id = Number(rows[i]?.execution_intent_id || rows[i]?.id || 0);
            if (id > 0) return id;
        }
        return 0;
    }

    function resolveExpansionCurrentReadiness({
        page = '',
        marketEvaluationResult = null,
        benchmarkModelResult = null,
        collaborationEfficiencyResult = null,
    } = {}) {
        if (['market-evaluation', 'market-eval'].includes(page)) {
            return marketEvaluationResult?.project_readiness || null;
        }
        if (page === 'benchmark-model') {
            return benchmarkModelResult?.project_readiness || marketEvaluationResult?.project_readiness || null;
        }
        if (['collaboration-efficiency', 'sync-efficiency'].includes(page)) {
            return collaborationEfficiencyResult?.project_readiness
                || benchmarkModelResult?.project_readiness
                || marketEvaluationResult?.project_readiness
                || null;
        }
        return collaborationEfficiencyResult?.project_readiness
            || benchmarkModelResult?.project_readiness
            || marketEvaluationResult?.project_readiness
            || null;
    }

    return {
        marketEvaluationCityTierOptions,
        marketEvaluationCityOptions,
        strategyDistrictOptionsByCity,
        strategyAddressKeywordSuffixes,
        strategyLocationSuffixesByCityTier,
        strategyAddressKeywordOptionsByDistrict,
        strategyCompetitorCountByTierGrade,
        strategyCompetitorCityAdjustment,
        marketEvaluationDecorationOptions,
        marketEvaluationCustomerOptions,
        marketEvaluationConditionFields,
        marketEvaluationDefaults,
        marketEvaluationTierOfCity,
        marketEvaluationCityOptionsForTier,
        secondaryMarketEvaluationCustomerOptions,
        strategyDistrictOptionsForCity,
        strategyAddressKeywordOptionsForLocation,
        isKnownStrategyAddressKeyword,
        strategyCityOptionsForProject,
        strategyDistrictOptionsForProject,
        strategyAddressKeywordOptionsForProject,
        strategyNextDistrictForProject,
        strategyNextAddressForProject,
        estimateStrategyCompetitorCount,
        normalizeMarketEvaluationForm,
        buildFeasibilityInputCards,
        buildFeasibilityReportCards,
        buildFeasibilityAiEmpowerment,
        buildFeasibilityPayload,
        feasibilityDecisionClassForGrade,
        stringifyFeasibilityReport,
        buildMarketEvaluationAiRiskSuggestions,
        marketEvaluationRiskSeverityClass,
        formatMarketEvaluationScoreChange,
        marketEvaluationScoreChangeClass,
        normalizeAiRecommendationDisplay,
        buildMarketEvaluationAiJudgementRows,
        buildMarketEvaluationAiRecommendations,
        buildMarketEvaluationAiAssumptions,
        buildMarketEvaluationScoreFormula,
        buildMarketEvaluationScoreBreakdown,
        buildMarketEvaluationScorePercent,
        buildMarketEvaluationAiRiskNote,
        benchmarkModelAiSourceLabelForResult,
        buildBenchmarkModelAiRecommendations,
        buildBenchmarkModelAiWatchPoints,
        buildBenchmarkModelAiAssumptionNote,
        benchmarkModelDataNoticeForResult,
        buildBenchmarkModelAiOutcomeCards,
        normalizeStrategyAiEvaluation,
        normalizeStrategyResult,
        buildStrategyPayload,
        buildStrategyScoreCards,
        strategyFreshnessLabelForSnapshot,
        strategyAiSourceLabelForResult,
        strategyAiModelDisplayLabelForSnapshot,
        strategyPoiDataSourceLabelForSnapshot,
        strategyDataNoticeForSnapshot,
        buildStrategyDataSourceRows,
        buildStrategyAiEmpowermentCards,
        expansionRecordTypeLabel,
        expansionReadinessBadgeClass,
        expansionReadinessMissingText,
        feasibilityReadinessBadgeClass,
        feasibilityReadinessMissingText,
        executionIntentIdFromRecord,
        resolveExpansionCurrentReadiness,
    };
})();
