# 🚛 Sefer Takip

Sarım (yükleme) ve boşaltım depoları, şoförler, ürünler, irsaliye/kantar fişleri ve tüm seferlerin takibi.
iPhone'dan kullanılacak şekilde tasarlandı. **PHP + SQLite** ile çalışır, ayrıca veritabanı sunucusu gerekmez.

---

## Özellikler

- **Depolar:** Yükleme (sarım) ve boşaltım depoları; il, ilçe, adres ve **koordinat**. Koordinat şu yollarla girilebilir:
  - Google, Apple veya Yandex harita linki yapıştırarak (kısa linkler dahil)
  - `40.8167, 32.8930` ya da `40°49'00"N 32°53'35"E` yazarak
  - Depodayken "Şu an bulunduğum konum" butonuyla

  Enlem/boylam ters girilirse veya konum Türkiye dışındaysa sistem uyarır.
- **Şoförler:** TC (algoritma kontrolü), cep telefonu, plakalar, IBAN (kontrol edilir). Aynı TC ile ikinci kayıt engellenir.
- **Ürünler:** Ton fiyatı + KDV oranı.
- **Seferler:**
  - Mal bedeli ve nakliye ücreti KDV hariç/dahil olarak otomatik hesaplanır.
  - Hatalı sayılar ("27 ton", "abc", eksi değer) **kaydedilmez**, uyarı verilir.
  - Aynı irsaliye/fiş no başka seferde varsa uyarı verilir.
- **İki taraflı sefer:** Her seferin **yükleme tarafı** ve **boşaltım tarafı** ayrı tutulur. Her tarafta kantar net kg, irsaliye no, kantar fiş no ve kendi belgeleri vardır:
  - Yükleme irsaliyesi ve yükleme kantar fişi
  - Boşaltım irsaliyesi ve boşaltım kantar fişi

  Araç boşaltınca sefer sayfasındaki **"Boşaltımı Kaydet"** kutusundan boşaltım bilgileri ve belgeleri girilir, sefer "Teslim Edildi" olur.
- **Fire takibi:** Yükleme ile boşaltım kantarı arasındaki fark (kg ve %) otomatik hesaplanır. Fark **fire toleransını** aşarsa (varsayılan %0,5) uyarı verilir. Listede "boşaltım kantarı bekleyen" ve "toleransı aşan" seferler süzülebilir.
- **Ücret hesap esası:** Mal bedeli ve nakliye ücreti her sefer için ayrı ayrı şu esaslardan birine göre hesaplanabilir:
  - **yükleme kantarı**
  - **boşaltım kantarı**
  - **hangisi düşükse**

  Varsayılanlar yönetici **Ayarlar** sayfasından seçilir. Boşaltım girilene kadar tutar geçici olarak yükleme kantarına göre gösterilir.
- **Sefer Fişi:** İki tarafı, fireyi, ücretleri ve imza alanlarını gösteren yazdırılabilir fiş. iPhone'da Yazdır → PDF olarak kaydedip paylaşılabilir. **Fiyatsız fiş** (şoför / fabrika için) ücretleri göstermez.
- **WhatsApp:** `wa.me/905444067494?text=...` formatında konumlar ve belge linkleri gönderilir. iPhone'da PDF dosyasının kendisi de paylaşılabilir.
- **Filtreleme:** Tarih, ürün, depo, şoför, plaka, durum, ödeme durumu ve Türkçe karakter uyumlu arama. Toplamlar ve ürün bazında özet gösterilir.
- **Excel:** Filtrelenmiş seferler veya tüm veriler. Tarihler gerçek Excel tarihidir; sıralama ve filtre çalışır.
- **Değişiklik geçmişi:** Her düzenleme kim / ne zaman / eski → yeni olarak kaydedilir; tek bir değişiklik **geri alınabilir**.
- **Form taslağı:** Yazılan bilgiler tarayıcıda saklanır. Yanlış linke basılırsa, dosya çok büyük olursa veya sayfa yenilenirse bilgiler geri getirilir.

## Kullanıcılar ve güvenlik

| Konu | Uygulamadaki karşılığı |
|---|---|
| Roller | **Yönetici:** kullanıcı yönetimi, ayarlar, yedek, geri yükleme. **Kullanıcı:** sefer, depo, şoför ve ürün işlemleri. |
| Kurulum | İlk kurulum sadece `config.local.php` içindeki **kurulum anahtarı** ile yapılabilir. |
| Şifre | En az 8 karakter. 15 dakikada 5 hatalı denemeden sonra giriş geçici olarak kilitlenir. |
| İşten ayrılan personel | Kullanıcı **pasifleştirilir**: giriş yapamaz, kayıtları ve geçmişi korunur. |
| Kaybolan telefon | Şifre değişince veya "oturumları kapat" denince diğer cihazlardaki oturumlar kapanır. |
| HTTPS | `https_zorunlu` açıksa tüm trafik HTTPS'e yönlendirilir ve HSTS gönderilir. |
| Hata gösterimi | Hatalar ziyaretçiye gösterilmez; veri klasöründeki `hata.log` dosyasına kod numarasıyla yazılır. |
| Güvenlik testi | Veri klasörü internetten okunabiliyorsa yönetici panelinde kırmızı uyarı çıkar. |
| Belge linkleri | Şoföre giden linkler giriş istemez ama tahmin edilemez (32 haneli). Belge "Kaldır"ılınca link çalışmaz. Linkin süresi dolmaz; elinde link olan herkes açabilir. |

## Verilerin korunması

1. **Hiçbir kayıt kalıcı silinmez.** Silinenler sayfasından veya seferin kendi sayfasından geri alınabilir.
2. **Değişiklik geçmişi:** Yanlış bir düzenleme tek tıkla geri alınabilir.
3. **Otomatik günlük yedek:** Her gün ilk değişiklikten önce sunucuda veritabanı kopyası alınır (180 gün saklanır).
4. **Tam yedek (ZIP):** Veritabanı + tüm belgeler + Excel tek dosyada. İsteğe bağlı AES-256 şifreli alınabilir.
   7 gün ZIP indirilmezse yöneticiye uyarı çıkar. 👉 **Haftada bir indirip bilgisayar / iCloud / Drive'da saklayın.**
5. **Geri yükleme:** `.zip` veya `.sqlite` yüklenir. Önce karşılaştırma ekranı açılır (şu an X sefer / yedekte Y sefer); onay olmadan hiçbir şey değişmez ve mevcut hal ayrıca yedeklenir.

---

## Kurulum (cPanel / Plesk / herhangi bir PHP hosting)

**Gereksinim:** PHP 7.4 veya üzeri (**8.1+ önerilir**). Eklentiler: `pdo_sqlite`, `mbstring`, `zip`, `fileinfo`, `curl`. Bir de SSL sertifikası (çoğu hostingde ücretsiz Let's Encrypt).

### Hosting seçimi (ne almalı?)

Normal bir **Linux paylaşımlı hosting** yeterlidir (cPanel, Plesk veya DirectAdmin panelli). Hosting firmasına şu soruları sorun:

| Sorulacak | Gereken |
|---|---|
| PHP sürümü | 8.1 veya üzeri (8.2 / 8.3 ideal). En az 7.4 |
| PHP eklentileri | `pdo_sqlite`, `zip`, `mbstring`, `fileinfo`, `curl` açık mı? |
| SSL | Ücretsiz Let's Encrypt var mı? |
| Klasör | `public_html` dışında klasör açabiliyor muyum? |
| Yükleme sınırı | `upload_max_filesize` en az 20 MB, `post_max_size` en az 60 MB yapılabiliyor mu? |
| Disk | Belgeler biriktikçe büyür; **en az 20 GB** önerilir |
| Yedek | Günlük otomatik yedek var mı? |
| Alt alan adı | `takip.firmaniz.com` gibi bir subdomain açabiliyor muyum? |

**Disk tahmini:** Telefonla çekilen bir fotoğraf 2-4 MB, taranmış bir PDF sayfası 0,2-0,5 MB'tır. Günde 10 sefer × 4 belge ile yılda kabaca **5-15 GB** birikir.
👉 Belgeleri fotoğraf yerine **PDF olarak tarayın** (iPhone Notlar / Dosyalar → "Belgeleri Tara"); hem net okunur hem 5-10 kat az yer kaplar.

### WordPress hosting'de çalışır mı?

- **Evet, çalışır.** Türkiye'deki "WordPress hosting" paketleri genelde WordPress kurulu normal cPanel/Plesk hostingdir. Sefer Takip, WordPress'ten bağımsız ayrı bir klasörde çalışır. En iyisi ayrı bir alt alan adına kurmaktır (ör. `takip.firmaniz.com`).
- **Temanın içinde çalışmaz, çalışması da gerekmez.** Kendi arayüzü vardır. Firma sitenize sadece "Sefer Takip" linki koymanız yeterli.
- **Dikkat 1:** "Yönetilen WordPress" platformları kendi PHP uygulamanızı kurmanıza genelde izin vermez; bunlar uygun değildir. Örnek: WordPress.com, WP Engine, Kinsta. Hosting firmasına "WordPress dışında kendi PHP uygulamamı subdomain'e kurabilir miyim?" diye sorun.
- **Dikkat 2 (güvenlik):** Aynı hosting hesabındaki WordPress (eklentiler, temalar) ele geçirilirse saldırgan aynı hesaptaki dosyalara da erişebilir. WordPress ve eklentileri güncel tutun. Mümkünse Sefer Takip için **ayrı bir hosting paketi / hesabı** kullanın.
- **WordPress eklentisi olarak yeniden yazmak önerilmez:**
  - Sistem bağımsız olarak hazır ve test edildi; eklentiye çevirmek uzun iş ve yeni hata riski demek.
  - WordPress'in yükleme klasörü varsayılan olarak herkese açıktır; irsaliye ve TC bilgileri için ek koruma gerekir.
  - Eklenti ve tema güncellemeleri sistemi bozabilir.
  - wp-admin telefonda bu arayüz kadar kullanışlı değildir.

### Canlıya alma kontrol listesi

1. **Veri klasörünü oluşturun:** web klasörünün (`public_html`) **dışında** olmalı. Örnek: `/home/KULLANICIADI/sefer-data`, izin 775.
2. **Dosyaları yükleyin:** `sefer-takip` klasörünün içeriğini hostinge yükleyin (ör. `public_html/takip/`). `data/` klasörü varsa **yüklemeyin**.
3. **Ayar dosyasını hazırlayın:** `config.local.ornek.php` dosyasını kopyalayıp adını `config.local.php` yapın ve doldurun:
   - `data_dir` → 1. adımdaki klasör
   - `base_url` → `https://takip.firmaniz.com` gibi sitenin adresi
   - `kurulum_anahtari` → en az 12 karakter, tahmin edilemez bir metin
   - `https_zorunlu` → `true`
4. **Kurulumu yapın:** Siteyi açın, kurulum anahtarıyla yönetici hesabını oluşturun. Kurulum sayfasındaki "Sunucu kontrolü"nde kırmızı satır kalmamalı.
5. **Veri klasörünün kapalı olduğunu doğrulayın:** Yönetici panelinde kırmızı "veri klasörü erişilebilir" uyarısı **çıkmamalı**. Yedek / Excel → Sistem Durumu ekranında "Veri klasörü konumu: Web klasörünün dışında" yazmalı.
6. **Yükleme sınırını test edin:** Telefondan büyük bir fotoğraf ve PDF yükleyin. Sınır Sistem Durumu ekranında görünür. Küçükse `.user.ini` / hosting panelinden `upload_max_filesize` ve `post_max_size` değerlerini yükseltin.
7. **Yedeklemeyi kurun:**
   - Hostingin kendi otomatik yedeğini (JetBackup vb.) açın.
   - İlk ZIP yedeği indirin.
   - **Boş bir kurulumda geri yükleme provası yapın.** Yedeğin işe yaradığını görmenin tek yolu budur.
8. **Ayarları yapın:** Yönetici → **Ayarlar** sayfasında şunları girin (anlaşmanıza göre):
   - Fişte görünecek firma adı ve bilgileri
   - Mal ve nakliye için varsayılan hesap esası (yükleme / boşaltım / düşük olan)
   - Fire toleransı
9. **Kullanıcıları ekleyin:** Personele normal kullanıcı hesabı açın; yönetici sayısını az tutun.
10. **Deneme sürümünü kapatın:** GitHub Pages deneme sürümü (`docs/`) açıksa personelin oraya veri girmemesi için kapatın. Sayfanın üstünde zaten "DENEME" bandı var.

> **Nginx kullanıyorsanız** (.htaccess çalışmaz): veri klasörü zaten web klasörü dışında olmalı. Ek olarak şunu ekleyin:
> ```nginx
> location ~ ^/takip/(lib|pages|data)/ { deny all; }
> location ~ ^/takip/config(\.local)?\.php$ { deny all; }
> ```

### iPhone ana ekranına ekleme

Safari'de siteyi açın → **Paylaş → Ana Ekrana Ekle**. Uygulama gibi açılır.

### Bilgisayarda deneme

```bash
cd sefer-takip
cp config.local.ornek.php config.local.php   # data_dir ve kurulum_anahtari'nı düzenleyin
php -S localhost:8000
```

## Güncelleme nasıl yapılır?

1. Önce **Yedek / Excel → Tam Yedeği İndir (ZIP)**.
2. Yeni sürümün dosyalarını yükleyip eskilerin üzerine yazın:
   - `config.local.php` dosyasına **dokunmayın**; yeni sürümde bu dosya yoktur, bu yüzden ezilmez.
   - `data/` klasörünü veya veri klasörünü **yüklemeyin, silmeyin**.
3. Siteyi açın. Veritabanı gerekiyorsa kendiliğinden yeni sürüme yükseltilir (eski veriler korunur).
4. Tarayıcı eski görünümü gösterirse sayfayı yenileyin.

> Güncellemeden sonra **kurulum ekranı** çıkıyorsa verileriniz silinmemiştir. `config.local.php` eksik ya da `data_dir` yanlış demektir; dosyayı geri koyun.

## Büyük yedeği geri yükleme (FTP ile)

ZIP, sunucunun yükleme sınırından büyükse:

1. ZIP dosyasını FTP ile **veri klasöründeki `yedekler/`** klasörüne yükleyin.
2. Yedek / Excel sayfasındaki "Sunucudaki Yedekler" listesinde görünür. Şifreliyse şifreyi yazıp **ZIP'ten geri yükle…** butonuna basın.
3. Karşılaştırma ekranında onaylayın.

## Sorun giderme

- **"Bir hata oluştu — kod: abcd1234":** Veri klasöründeki `hata.log` dosyasında bu kodu arayın.
- **Belge yüklenmiyor / "dosyalar çok büyük":** Sistem Durumu'ndaki yükleme sınırına bakın. Formdaki bilgiler kaybolmaz; "Taslağı geri yükle" ile geri gelir.
- **Kısa harita linki çözülmüyor:** Hosting dış bağlantıya izin vermiyor olabilir. Koordinatı haritadan kopyalayıp yapıştırın.
- **Şifre unutuldu:**
  - Başka bir yönetici, Kullanıcılar sayfasından yeni şifre verebilir.
  - Tek yönetici sizseniz: hosting dosya yöneticisiyle `config.local.php` dosyasına şu satırı ekleyin:

    ```php
    'acil_sifre_sifirlama' => ['kullanici' => 'admin', 'yeni_sifre' => 'Yeni-Guclu-Sifre-2026'],
    ```

    Siteyi bir kez açın ve yeni şifreyle girin; ardından **satırı hemen silin** (silinene kadar panelde uyarı görünür).

## Muhasebe ve hukuk notları (uzmana teyit ettirin)

- **KDV oranları:** Varsayılan %20. Her ürünün doğru oranını ürün kartına girin; fındık gibi ürünlerde oran farklı olabilir.
- **KDV tevkifatı:** Yük taşımacılığında (2/10) uygulanıyor olabilir. Sistem şu an hesaplamıyor; gerekiyorsa "tevkifat oranı" alanı eklenebilir.
- **KVKK:** TC, telefon ve IBAN kişisel veridir. Gerekenler:
  - HTTPS, güçlü şifreler, yedeklerin güvenli saklanması (şifreli ZIP önerilir)
  - Şoförlere aydınlatma metni
  - Sadece gerekli bilgilerin girilmesi
