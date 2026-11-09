# 🚛 Sefer Takip

Sarım (yükleme) ve boşaltım depoları, şoförler, ürünler, irsaliye / kantar fişleri ve tüm seferlerin takibi.
iPhone'dan rahat kullanılacak şekilde tasarlandı. **PHP + SQLite**: veritabanı kurulumu gerektirmez, her hostingde çalışır.

## Özellikler

- **Depolar:** Yükleme (sarım) ve boşaltım depoları; il, ilçe, adres ve **koordinat**.
  Koordinat; Google Maps linki yapıştırarak (kısa `maps.app.goo.gl` linkleri dahil), `40.81, 32.89` yazarak
  veya depodayken **"Şu an bulunduğum konum"** butonuyla girilir. Harita önizlemesi ile kontrol edilir.
- **Şoförler:** Ad, soyad, TC, telefon, çekici ve dorse plakası, IBAN. Sefer formundan da hızlıca yeni şoför eklenebilir.
- **Ürünler:** Ton fiyatı + KDV oranı (ör. Fındık 9.000 ₺ + KDV).
- **Seferler:** Tarih, güzergah, şoför/araç, ürün ve kg, ürün ton fiyatı, nakliye ton fiyatı (ör. 100 ₺ + KDV),
  irsaliye no, kantar fiş no, durum (Planlandı / Yolda / Teslim / İptal), nakliye ödendi mi.
  Mal bedeli ve nakliye ücreti KDV hariç/dahil **otomatik hesaplanır**.
- **Belgeler:** İrsaliye ve kantar fişi PDF'i (veya telefonla çekilmiş fotoğrafı) sefere yüklenir.
- **WhatsApp:** Sefer sayfasında tek tuşla `wa.me/905444067494?text=...` formatında şoföre gönderim:
  - 📍 Yükleme ve boşaltım konumları (Google Maps linkleri)
  - 📄 İrsaliye ve kantar fişi linkleri
  - 📎 iPhone'da **PDF dosyalarının kendisini** paylaşma (WhatsApp'a dosya olarak gider)
- **Filtreleme:** Tarih aralığı (Bugün / Bu hafta / Bu ay / Geçen ay hızlı seçim), ürün, yükleme deposu, boşaltım deposu,
  şoför, plaka, durum, ödeme durumu ve serbest arama. Toplam ton / tutarlar ve ürün bazında özet.
- **Excel:** Filtrelenmiş seferler veya tüm veriler (Seferler, Ürün Özeti, Depolar, Şoförler, Ürünler, Belgeler, İşlem Geçmişi) `.xlsx` olarak.
- **Her şey düzenlenebilir**; her değişiklik kim/ne zaman/eski → yeni değer olarak geçmişe yazılır.

## Veriler nasıl korunur? (Kayıp olmaması için)

1. **Hiçbir kayıt kalıcı silinmez.** "Sil" denilen sefer, depo, şoför, ürün ve belge *Silinenler* sayfasına düşer, geri alınabilir.
2. **Değişiklik geçmişi:** Her düzenleme eski ve yeni değeriyle saklanır.
3. **Otomatik günlük yedek:** Her gün ilk değişiklikten önce veritabanının kopyası `data/yedekler/` klasörüne alınır (180 gün saklanır).
4. **Tam yedek (ZIP):** *Yedek / Excel* sayfasından veritabanı + tüm PDF'ler + Excel tek dosyada indirilir.
   👉 Haftada en az bir kez indirip bilgisayar / iCloud / Google Drive'da saklayın (sunucu çökmesine karşı).
5. **Geri yükleme:** Sunucudaki bir yedeğe tek tuşla dönülebilir veya ZIP içindeki `veritabani.sqlite` yüklenebilir.
   Geri dönmeden önce mevcut hal ayrıca yedeklenir.

## Kurulum (cPanel / Plesk / herhangi bir PHP hosting)

Gereksinim: **PHP 7.4 veya üzeri**, `pdo_sqlite` ve `zip` eklentileri (çoğu hostingde varsayılan açıktır). HTTPS (SSL) önerilir.

1. `sefer-takip` klasörünün içeriğini hostinginize yükleyin (ör. `public_html/takip/`).
2. Tarayıcıdan adresi açın (ör. `https://firmaniz.com/takip/`).
3. İlk açılışta yönetici kullanıcı adı ve şifresini oluşturun.
4. Sırasıyla: **Depolar** → yükleme ve boşaltım depolarını, **Şoförler**, **Ürünler** ekleyin; sonra **Yeni Sefer**.

İsteğe bağlı ayarlar `config.php` dosyasındadır:

- `data_dir`: Veritabanı, belgeler ve yedeklerin klasörü. Güvenlik için web klasörünün **dışında** bir yol verebilirsiniz
  (ör. `/home/kullanici/sefer-data`).
- `base_url`: WhatsApp'a giden belge linklerinin başı (boşsa otomatik algılanır).
- Varsayılan KDV oranları, maksimum dosya boyutu, yedek saklama süresi.

`data/` klasörü Apache'de `.htaccess` ile dışarıya kapatılır. **Nginx** kullanıyorsanız şu kuralı ekleyin:

```nginx
location ~ ^/takip/(data|lib|pages)/ { deny all; }
location = /takip/config.php { deny all; }
```

Bilgisayarda denemek için: `cd sefer-takip && php -S localhost:8000` → `http://localhost:8000`

### iPhone ana ekranına ekleme

Safari'de siteyi açın → Paylaş → **Ana Ekrana Ekle**. Uygulama gibi açılır.

## Örnek kullanım

> Erpa Depo'dan Ahmet Yılmaz (TC 12173456894, 45AAR75 / 45AAR12) 27.000 kg fındık (9.000 ₺/ton + KDV) yükleyip
> Çankırı / Çerkes'teki Alparslan Kağıt Fabrikası'na götürüyor; nakliye 100 ₺/ton + KDV.

1. *Yeni Sefer* → Yükleme: Erpa Depo, Boşaltım: Alparslan Kağıt Fabrikası
2. Şoför: "Yeni şoför ekle" → Ahmet / Yılmaz / TC, telefon `0544 406 74 94`, plakalar
3. Ürün: Fındık (fiyat otomatik gelir), Miktar: `27000`, Nakliye ton fiyatı: `100`
4. İrsaliye ve kantar fişi PDF'lerini seçip **Kaydet**
5. Açılan sefer sayfasında **📤 Hepsini Gönder** → WhatsApp şoförün numarasıyla açılır.

Hesap: 27 ton × 9.000 ₺ = **243.000 ₺ + KDV**, nakliye 27 ton × 100 ₺ = **2.700 ₺ + KDV**.

## Güvenlik notları

- Sisteme sadece kullanıcı adı/şifre ile girilir; birden fazla kullanıcı eklenebilir (*Kullanıcılar* sayfası, sağ üstteki isim).
- Şoföre gönderilen belge linkleri giriş gerektirmez ama 32 haneli rastgele anahtar içerir, tahmin edilemez.
  Kaldırılan belgenin linki şoför için çalışmaz.
- `data/` klasörü asla git'e gönderilmez (`.gitignore`).
