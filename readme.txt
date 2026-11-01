=== Ziyaretçi Takibi ===
Contributors: szksoft
Tags: ziyaretci, analytics, ip, takip, istatistik
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.9.2
License: GPL-2.0-or-later

Gelişmiş ziyaretçi takibi: IP kayıtları, konum tespiti + dünya haritası, canlı izleme, UTM kampanya, dönem karşılaştırma, yeni-vs-dönen, huni analizi, gerçek zamanlı uyarılar, IP engelleme (uygulama + sunucu düzeyi) ve modern bir istatistik paneli.

== Description ==

Sitenizi kimlerin, ne zaman, hangi cihazla ve nereden ziyaret ettiğini modern bir panelde gösterir.

**Özellikler (Faz 1)**

* Her sayfa görüntülemesi veritabanına kaydedilir: IP, sayfa, başlık, kaynak (referrer), cihaz, tarayıcı, işletim sistemi, sayfa türü
* IP konum tespiti (ülke, şehir, ISS) — arka planda toplu sorgu, kalıcı önbellek
* Bot tespiti (60+ bilinen bot imzası) ve bot filtreleme
* Genel Bakış paneli: istatistik kartları, zaman serisi grafiği, cihaz/tarayıcı/ülke/kaynak dağılımları, en çok görüntülenen sayfalar, son ziyaretler
* Ziyaretler ekranı: zaman aralığı, IP, ülke, cihaz, tarayıcı, işletim sistemi, bot ve metin araması ile filtreleme + sayfalama
* Ziyaretçiler ekranı: IP bazlı profil — ilk/son görülme, toplam ziyaret, oturumlara ayrılmış davranış akışı (30 dk kuralı)
* CSV dışa aktarma (filtrelerle birlikte, Türkçe Excel uyumlu)
* Veri saklama süresi + kayıt tavanı ile otomatik temizlik (veritabanı şişmez)
* KVKK dostu IP anonimleştirme seçeneği
* Rol ve IP bazlı hariç tutma
* WordPress ana paneline özet widget'ı

**Özellikler (Faz 2)**

* Canlı izleme ekranı: nabız sayacı, gerçek zamanlı ziyaret akışı, şu an aktif sayfalar, dakikalık sparkline (8 sn'de bir yenilenir, duraklatılabilir)
* JS Beacon kayıt modu: sayfa önbellekten sunulsa bile ziyaretleri kaydeder (WP Super Cache, LiteSpeed, WP Rocket uyumlu)
* UTM kampanya takibi: utm_source / utm_medium / utm_campaign yakalama, panelde kampanya kırılımı, filtre ve CSV sütunları
* Haftalık e-posta özet raporu (seçilen gün, özelleştirilebilir alıcı, test gönderimi)

**Özellikler (Faz 3)**

* Ziyaretçi haritası: ülkeleri ziyaretçi yoğunluğuna göre renklendiren, kendi kendine yeten (harici istek yok) interaktif dünya haritası (choropleth); ülkeye tıklayarak ziyaretleri filtreleme
* Dönem karşılaştırmalı Raporlar ekranı: bu dönem vs önceki dönem (7/30/90 gün), üst üste trend grafiği, ülke/sayfa/cihaz kırılımlarında artış-azalış
* IP engelleme (kara liste): ziyaretçi/profil ekranından tek tıkla engelleme; engellenen IP'ler sitede 403 alır ve kaydedilmez; ayarlardan liste yönetimi ve ana açma/kapama

**Özellikler (Faz 4)**

* Gerçek zamanlı uyarılar: trafik artışı, 404 fırtınası ve bot artışı eşikleri (son 1 saat) + izlenen IP ziyaretinde anlık uyarı; panel bildirim çanı (okunmamış rozeti) ve isteğe bağlı e-posta
* Yeni vs dönen ziyaretçi analizi: günlük yığılmış grafik, dönem toplamı halkası, dönen oranı ve önceki döneme göre değişim (Raporlar ekranında)

**Özellikler (Faz 5)**

* Sunucu düzeyi engelleme kuralı üretici: kara listeyi Apache (.htaccess) ve Nginx kurallarına çevirir (IP + * → CIDR); tek tıkla kopyalama; yazılabilirse .htaccess'e otomatik yazma/kaldırma (WP işaretçileriyle) ve kara liste değiştikçe otomatik güncelleme
* Huni / segment analizi: sıralı adımlarla (URL parçası) dönüşüm hunisi; adım editörü (ekle/sil/sırala), zaman penceresi, adım adım geçiş oranı ve terk (drop-off) görselleştirmesi; huni tanımı kaydedilir

**Özellikler (Faz 6)**

* Çoklu kayıtlı huni: birden fazla huniyi adlandırıp kaydetme, sekmelerle geçiş, oluştur/güncelle/sil
* PDF rapor dışa aktarma: Genel Bakış ve Raporlar ekranından tek tıkla yazdırılabilir rapor (istatistikler, trend grafiği, ülke/sayfa/kaynak/cihaz tabloları); tarayıcının "PDF olarak kaydet" özelliğiyle indirilir

**Özellikler (Faz 7)**

* Haftalık e-posta raporuna PDF eki: kendi kendine yeten minimal PDF motoruyla (harici kütüphane yok) üretilen tek sayfalık biçimlendirilmiş rapor otomatik olarak e-postaya eklenir; ayardan açılıp kapatılabilir
* Canlı ziyaretçi haritası: Canlı ekranında dünya haritası; gelen ziyaretler ziyaretçinin konumunda genişleyen bir dalga (ping) ve ülke vurgusuyla anlık belirir (insan yeşil, bot sarı)

**Özellikler (Faz 8)**

* Tıklama/kaydırma ısı haritası: ön yüzdeki tıklama konumları (kendi kendine yeten canvas ısı motoru) ve sayfa derinliğine ulaşma yüzdeleri; sayfa bazında görüntülenir; örnekleme ile hacim kontrolü
* Kohort (retention) analizi: haftalık edinim kohortları × sonraki haftalarda geri dönüş yüzdesi (renk kodlu ızgara)
* A/B segment karşılaştırma: iki segmenti (URL / kampanya / kaynak / cihaz / ülke) seçilen dönemde yan yana karşılaştırma (ziyaretçi, görüntüleme, sayfa/ziyaret)

**Konum verisi hakkında**

Konum tespiti için IP adresleri ip-api.com servisine toplu olarak gönderilir ve sonuçlar sitenizin veritabanında önbelleklenir. Her IP yalnızca bir kez sorgulanır. Ayarlardan tamamen kapatılabilir.

**Kayıt yöntemi hakkında**

Varsayılan PHP modu sunucu tarafında çalışır, ek istek üretmez. Sayfa önbellekleme eklentisi kullanıyorsanız Ayarlar > Kayıt yöntemi'nden "JS Beacon" moduna geçin; ziyaretler tarayıcıdan REST ucuna gönderilir ve önbellek atlanmaz.

== Changelog ==

= 1.9.2 =
* Tarih filtresi takvim ikonu: bir önceki düzeltmedeki `background: transparent` kuralı Chrome'un ikon görselini de sildiği için takvim açılıyor ama düğme görünmüyordu; kaldırıldı, ikon artık görünür.

= 1.9.1 =
* Saat Dilimi kartındaki uyarı simgesi boyutsuz kaldığı için bazı tarayıcılarda devasa görünüyordu; düzeltildi (ve panel genelinde benzer bir risk taşıyan tüm simgeler tarandı, profil çekmecesi kapatma düğmesine de önlem eklendi).

= 1.9.0 =
* Sunucunun kendi sistem saatine güvenmek yerine harici, güvenilir kaynaklardan (Cloudflare / HTTPS yanıt üstbilgisi) periyodik olarak gerçek zamanı öğrenip düzeltme uygulayan bir zaman senkronizasyonu eklendi (bazı sanallaştırılmış sunuculardaki saat kayması sorununu çözer). Ayarlar'da durum göstergesi ve manuel senkronizasyon butonu.
* Ziyaretçi profil çekmecesi artık WordPress yönetim çubuğunun üzerine binmiyor.
* Panel genelinde okunabilirlik: soluk gri metinler daha net görünecek şekilde aydınlatıldı; profil çekmecesi, onay pencereleri ve bildirimlerin tasarım sistemi renklerini hiç almadığı (WP'nin varsayılan soluk rengine düştüğü) bir kapsam hatası düzeltildi.
* Ziyaretler ekranındaki tarih filtrelerinde takvim seçici simgesi geri getirildi (koyu temada görünür ve tıklanabilir).

= 1.8.1 =
* Saat dilimi düzeltmesi: panel saatleri artık kaydın gerçek zaman anına çevrilip görüntüleyenin saat diliminde gösteriliyor ("3 sa önce" gibi yanlış göreli süreler düzeldi). Ayarlar'a site/tarayıcı saat dilimi durumunu gösteren bilgi kartı eklendi.

= 1.8.0 =
* Veri merkezi / proxy IP'lerini otomatik bot sayma seçeneği eklendi (gerçek tarayıcı kimliğiyle gelen datacenter trafiği insan istatistiklerinden temizlenir; geçmiş veri de geriye dönük düzeltilir).

= 1.7.0 =
* Tıklama/kaydırma ısı haritası eklendi.
* Kohort (retention) analizi ve A/B segment karşılaştırma eklendi.

= 1.6.0 =
* Haftalık e-posta raporuna PDF eki eklendi (kendi kendine yeten PDF motoru).
* Canlı ziyaretçi haritası (anlık ping animasyonu) eklendi.

= 1.5.0 =
* Çoklu kayıtlı huni (birden fazla adlandırılmış huni) eklendi.
* PDF rapor dışa aktarma eklendi.

= 1.4.0 =
* Sunucu düzeyi engelleme kuralı üretici (.htaccess/Nginx) eklendi.
* Huni / segment analizi ekranı eklendi.

= 1.3.0 =
* Gerçek zamanlı uyarılar (trafik/404/bot eşikleri + izlenen IP) ve bildirim çanı eklendi.
* Yeni vs dönen ziyaretçi analizi eklendi.

= 1.2.0 =
* İnteraktif ziyaretçi dünya haritası (choropleth) eklendi.
* Dönem karşılaştırmalı Raporlar ekranı eklendi.
* IP engelleme (kara liste) eklendi.

= 1.1.0 =
* Canlı izleme ekranı eklendi.
* JS Beacon kayıt modu (önbellek uyumu) eklendi.
* UTM kampanya takibi eklendi.
* Haftalık e-posta özet raporu eklendi.

= 1.0.0 =
* İlk sürüm.
