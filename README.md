# Ziyaretçi Takibi

WordPress için, ziyaretçileri **kendi veritabanınızda** kaydeden ve koyu temalı modern bir panelde gösteren ziyaretçi takip eklentisi. Üçüncü taraf analitik hesabı gerekmez.

*English: a self-hosted visitor analytics plugin for WordPress (live view, geo map, UTM, funnels, heatmaps, alerts, IP blocking). The admin UI is Turkish only.*

## Özellikler

- **Kayıt:** her sayfa görüntülemesi (IP, sayfa, kaynak, cihaz, tarayıcı, işletim sistemi, UTM) özel tablolara yazılır; bot tespiti (60+ imza) ve veri merkezi/proxy IP'lerini bot sayma
- **Panel:** genel bakış, canlı izleme (+ canlı ziyaretçi haritası), ziyaretler, ziyaretçi profilleri (oturum/davranış akışı), dönem karşılaştırmalı raporlar, yeni-vs-dönen analizi
- **Analiz:** konum (ülke/şehir/ISS) + dünya haritası, UTM kampanya takibi, sıralı dönüşüm hunileri, tıklama/kaydırma ısı haritası, haftalık kohort (retention), A/B segment karşılaştırma
- **Uyarılar:** trafik/404/bot eşikleri ve izlenen IP bildirimi (panel çanı + isteğe bağlı e-posta)
- **Engelleme:** IP kara listesi (uygulama içi 403) + Apache `.htaccess` / Nginx kural üretici
- **Raporlama:** CSV dışa aktarma, yazdırılabilir PDF rapor, PDF ekli haftalık e-posta özeti
- **Veri yönetimi:** saklama süresi, kayıt tavanı, KVKK/GDPR için IP anonimleştirme, rol ve IP bazlı hariç tutma
- **Önbellek uyumu:** varsayılan sunucu tarafı (PHP) kayıt; önbellek eklentisi kullanıyorsanız "JS Beacon" modu
- Grafikler ve dünya haritası eklentinin içindedir; **harici JS/CSS/yazı tipi yüklenmez**

## Gereksinimler ve uyumluluk

| | |
|---|---|
| WordPress | 5.8 veya üzeri (eklenti başlığındaki asgari değer) |
| PHP | 7.4 veya üzeri; tüm PHP dosyaları PHP 7.4 – 8.4 söz dizimi kurallarıyla ayrıştırılarak doğrulandı |
| Veritabanı | WordPress'in desteklediği MySQL / MariaDB (`dbDelta`, `UPDATE … JOIN`, `DELETE … ORDER BY … LIMIT` kullanılır; SQLite eşlemesinde tüm sorgular denenmemiştir) |
| PHP eklentileri | `mbstring` önerilir (PDF/UTF-8 işleri için) |

`readme.txt` içindeki "Tested up to" değeri şu an `6.8`'dir; daha yeni WordPress sürümleri için resmî bir test turu henüz yapılmamıştır.

## Kurulum

1. [Releases](../../releases) sayfasındaki `ziyaretci-takibi-x.y.z.zip` dosyasını **Eklentiler → Yeni Ekle → Eklenti Yükle** ile yükleyin ya da klasörü `wp-content/plugins/ziyaretci-takibi/` altına kopyalayın.
2. Eklentiyi etkinleştirin; tablolar otomatik oluşur.
3. Sol menüdeki **Ziyaretçi Takibi** → **Ayarlar**'dan saklama süresi, gizlilik ve kayıt yöntemini düzenleyin.

> GitHub'ın "Download ZIP" düğmesi klasörü `ziyaretci-takibi-main` adıyla verir. Bu yolla kuruyorsanız klasörü `ziyaretci-takibi` olarak yeniden adlandırın.

## Gizlilik ve dış hizmetler

Eklenti ziyaretçi IP adreslerini ve User-Agent bilgisini sitenizin veritabanında saklar. Bunu kullanmadan önce gizlilik politikanızı ve (gerekiyorsa) çerez/rıza düzeninizi gözden geçirin. Eklenti çerez **bırakmaz**; oturum ayrımı sorgu zamanında yapılır.

Aşağıdaki dış hizmetlere sunucunuzdan istek yapılır:

| Hizmet | Ne zaman | Gönderilen |
|---|---|---|
| `ip-api.com` (HTTP) | Konum tespiti açıkken, arka planda toplu | Ziyaretçi IP adresleri |
| `www.cloudflare.com/cdn-cgi/trace`, `api.wordpress.org`, `www.google.com/generate_204` | Zaman senkronizasyonu (cron) | Yalnızca sunucunun kendisinden giden boş istek |

- Konum tespitini **Ayarlar → Konum Tespiti**'nden kapatabilirsiniz.
- `ip-api.com` ücretsiz planı yalnızca ticari olmayan kullanım içindir ve şifrelemesiz HTTP kullanır; ticari/yoğun sitelerde kendi lisansınızı veya başka bir sağlayıcıyı değerlendirin.

## Bilinen sınırlamalar

- **Arayüz yalnızca Türkçedir**; metinler çeviriye hazır (`__()` ile) değildir.
- **İstemci IP'si:** varsayılan olarak IP, sırasıyla `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, `REMOTE_ADDR` başlıklarından okunur. Siteniz bir ters vekil/CDN arkasında değilse **Ayarlar → Gizlilik → "Vekil/CDN başlıklarına güven"** seçeneğini kapatın; aksi halde bu başlıklar istemci tarafından taklit edilebilir ve IP kara listesi/hariç tutma kuralları atlatılabilir.
- **Zaman doğrulaması:** varsayılan olarak en az 15 dakikada bir Cloudflare, WordPress.org ve Google'a istek yapılır; **Ayarlar → Saat Dilimi**'nden kapatılabilir.
- Sunucu düzeyi (`.htaccess`) engelleme `wp-admin` dahil tüm siteye uygulanır; eklenti, kendi IP'nizi engellemenize ve bu durumda `.htaccess`'e yazmanıza izin vermez.
- Önbellekten sunulan sayfalar WordPress'e ulaşmadığı için uygulama içi 403 onları kapsamaz; tam engelleme için sunucu kurallarını kullanın.

## Lisans

[GPL-2.0-or-later](LICENSE) — © SZK Soft
