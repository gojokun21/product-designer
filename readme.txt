=== Product Designer for WooCommerce ===
Contributors: yourname
Tags: woocommerce, product designer, customization, fabric.js, print-on-demand
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Editor vizual Fabric.js pentru personalizarea produselor WooCommerce: mockup, zonă printabilă, upload imagini, text, export PNG + JSON.

== Description ==
Product Designer permite clienților să personalizeze produsele dintr-un magazin WooCommerce: haine, textile, mugs, print-on-demand, etc. Adminul activează designerul pentru fiecare produs, setează imaginea mockup și zona printabilă, iar clientul folosește un editor vizual bazat pe Fabric.js pentru a adăuga text și imagini.

Fluxul complet:

* Admin activează designer-ul pe un produs și setează mockup + zonă printabilă (x, y, width, height).
* Clientul apasă "Personalizează" pe pagina produsului.
* Editorul deschide un modal cu canvas Fabric.js.
* Clientul adaugă text, încarcă imagini, mișcă, rotește, redimensionează și șterge elemente.
* La salvare, designul (JSON) și preview-ul (PNG) sunt salvate pe server.
* În coș și checkout apare preview-ul.
* La plasarea comenzii, designul se salvează în order item meta.
* Adminul vede preview-ul în pagina comenzii și poate descărca PNG + JSON.

== Architecture ==

Plugin PHP OOP cu autoloader PSR-4-ish.

* includes/class-plugin.php — bootstrap, instanțiază toate modulele.
* includes/core/ — Design_Storage, Image_Handler, Validator (logică pură).
* includes/admin/ — Product_Metabox, Order_Admin, Admin.
* includes/frontend/ — Frontend (buton + modal).
* includes/api/ — Rest_Api (POST /upload, POST /design, GET /design/:id).
* includes/woocommerce/ — Cart, Order (hook-uri WC).
* assets/ — CSS + JS (Fabric.js via CDN).

Design-urile se salvează pe disc în /uploads/product-designer/<design_id>.{json,png}.
În cart/order item meta se păstrează doar design_id + URL-ul preview-ului.

== Changelog ==
= 1.0.0 =
* MVP release.
