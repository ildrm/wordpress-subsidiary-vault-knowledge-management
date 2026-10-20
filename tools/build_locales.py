#!/usr/bin/env python3
"""Build initial navigation catalogs. Native review and complete workflow translation remain required."""
import json
from pathlib import Path
root=Path(__file__).resolve().parents[1]
keys='workspace tenant documents knowledge notifications members roles groups departments audit settings posture token signin create search title state version classification updated draft approved in_review rejected active suspended'.split()
values={
'en':['WORKSPACE','Subsidiary','Documents','Knowledge library','Activity inbox','People & access','Roles','Teams','Departments','Audit trail','Workspace settings','Group oversight','Development access token','Open workspace','Create document','Search','Title','Status','Version','Classification','Updated','Draft','Approved','In review','Rejected','Active','Suspended'],
'fa':['فضای کاری','شرکت تابعه','اسناد','کتابخانه دانش','صندوق فعالیت‌ها','افراد و دسترسی','نقش‌ها','تیم‌ها','واحدها','گزارش حسابرسی','تنظیمات فضای کاری','نظارت گروه','توکن دسترسی توسعه','ورود به فضای کاری','ایجاد سند','جستجو','عنوان','وضعیت','نسخه','طبقه‌بندی','به‌روزرسانی','پیش‌نویس','تأییدشده','در حال بررسی','ردشده','فعال','تعلیق‌شده'],
'fr':['ESPACE DE TRAVAIL','Filiale','Documents','Bibliothèque de connaissances','Activité','Personnes et accès','Rôles','Équipes','Départements','Journal d’audit','Paramètres','Supervision du groupe','Jeton de développement','Ouvrir l’espace','Créer un document','Rechercher','Titre','État','Version','Classification','Mis à jour','Brouillon','Approuvé','En révision','Rejeté','Actif','Suspendu'],
'tr':['ÇALIŞMA ALANI','Bağlı şirket','Belgeler','Bilgi kütüphanesi','Etkinlik kutusu','Kişiler ve erişim','Roller','Ekipler','Departmanlar','Denetim kaydı','Alan ayarları','Grup gözetimi','Geliştirme erişim belirteci','Çalışma alanını aç','Belge oluştur','Ara','Başlık','Durum','Sürüm','Sınıflandırma','Güncellendi','Taslak','Onaylandı','İncelemede','Reddedildi','Etkin','Askıya alındı'],
'ru':['РАБОЧЕЕ ПРОСТРАНСТВО','Дочерняя компания','Документы','База знаний','События','Люди и доступ','Роли','Команды','Отделы','Журнал аудита','Настройки','Надзор группы','Токен разработки','Открыть пространство','Создать документ','Поиск','Название','Статус','Версия','Классификация','Обновлено','Черновик','Одобрено','На проверке','Отклонено','Активно','Приостановлено'],
'zh-Hans':['工作空间','子公司','文档','知识库','活动收件箱','人员与访问','角色','团队','部门','审计日志','空间设置','集团监督','开发访问令牌','打开工作空间','创建文档','搜索','标题','状态','版本','分类','更新时间','草稿','已批准','审核中','已拒绝','启用','已暂停'],
'de':['ARBEITSBEREICH','Tochtergesellschaft','Dokumente','Wissensbibliothek','Aktivitäten','Personen und Zugriff','Rollen','Teams','Abteilungen','Auditprotokoll','Einstellungen','Gruppenaufsicht','Entwicklungszugriffstoken','Arbeitsbereich öffnen','Dokument erstellen','Suchen','Titel','Status','Version','Klassifizierung','Aktualisiert','Entwurf','Genehmigt','In Prüfung','Abgelehnt','Aktiv','Gesperrt'],
'pt':['ESPAÇO DE TRABALHO','Subsidiária','Documentos','Biblioteca de conhecimento','Atividade','Pessoas e acesso','Funções','Equipas','Departamentos','Registo de auditoria','Definições','Supervisão do grupo','Token de desenvolvimento','Abrir espaço','Criar documento','Pesquisar','Título','Estado','Versão','Classificação','Atualizado','Rascunho','Aprovado','Em revisão','Rejeitado','Ativo','Suspenso'],
'es':['ESPACIO DE TRABAJO','Filial','Documentos','Biblioteca de conocimiento','Actividad','Personas y acceso','Roles','Equipos','Departamentos','Registro de auditoría','Configuración','Supervisión del grupo','Token de desarrollo','Abrir espacio','Crear documento','Buscar','Título','Estado','Versión','Clasificación','Actualizado','Borrador','Aprobado','En revisión','Rechazado','Activo','Suspendido'],
'hi':['कार्यस्थान','सहायक कंपनी','दस्तावेज़','ज्ञान पुस्तकालय','गतिविधि इनबॉक्स','लोग और पहुँच','भूमिकाएँ','टीमें','विभाग','ऑडिट लॉग','कार्यस्थान सेटिंग','समूह निगरानी','विकास एक्सेस टोकन','कार्यस्थान खोलें','दस्तावेज़ बनाएँ','खोजें','शीर्षक','स्थिति','संस्करण','वर्गीकरण','अद्यतन','मसौदा','अनुमोदित','समीक्षाधीन','अस्वीकृत','सक्रिय','निलंबित'],
'ja':['ワークスペース','子会社','ドキュメント','ナレッジライブラリ','アクティビティ','ユーザーとアクセス','ロール','チーム','部門','監査ログ','設定','グループ監督','開発用アクセストークン','ワークスペースを開く','文書を作成','検索','タイトル','状態','バージョン','分類','更新日時','下書き','承認済み','レビュー中','却下','有効','停止'],
'ar':['مساحة العمل','الشركة التابعة','المستندات','مكتبة المعرفة','صندوق النشاط','الأشخاص والوصول','الأدوار','الفرق','الأقسام','سجل التدقيق','الإعدادات','إشراف المجموعة','رمز وصول التطوير','فتح مساحة العمل','إنشاء مستند','بحث','العنوان','الحالة','الإصدار','التصنيف','آخر تحديث','مسودة','معتمد','قيد المراجعة','مرفوض','نشط','معلق'],
'hy':['ԱՇԽԱՏԱՏԱՐԱԾՔ','Դուստր ընկերություն','Փաստաթղթեր','Գիտելիքների գրադարան','Գործունեություն','Մարդիկ և հասանելիություն','Դերեր','Թիմեր','Բաժիններ','Աուդիտի մատյան','Կարգավորումներ','Խմբի վերահսկողություն','Մշակման մուտքի նշան','Բացել աշխատատարածքը','Ստեղծել փաստաթուղթ','Որոնել','Վերնագիր','Վիճակ','Տարբերակ','Դասակարգում','Թարմացվել է','Սևագիր','Հաստատված','Ստուգման փուլում','Մերժված','Ակտիվ','Կասեցված'],
}
catalogs={}
for locale,translations in values.items():
    assert len(translations)==len(keys),locale
    catalogs[locale]=dict(zip(keys,translations))
payload=json.dumps(catalogs,ensure_ascii=False,indent=2)+'\n'
(root/'apps/admin-web/locales.json').write_text(payload)
(root/'packages/i18n/catalogs.json').write_text(payload)
mapping={'en':'en_US','fa':'fa_IR','fr':'fr_FR','tr':'tr_TR','ru':'ru_RU','zh-Hans':'zh_CN','de':'de_DE','pt':'pt_PT','es':'es_ES','hi':'hi_IN','ja':'ja','ar':'ar','hy':'hy'}
(root/'packages/i18n/locales.json').write_text(json.dumps({'mapping':mapping,'portuguese_default':'pt-PT','rtl':['fa','ar'],'review_status':'DRAFT / native and accessibility review outstanding','coverage':'Navigation and status labels only; remaining forms and notices use English.'},ensure_ascii=False,indent=2)+'\n')
print(f'Built {len(catalogs)} draft catalogs with {len(keys)} navigation/status messages each')
