<?php
/**
 * The built-in shipping provider registry.
 *
 * Each entry is keyed by its handle and holds:
 *
 * - `name`     — the display name shown to staff and customers
 * - `url`      — the public tracking URL, with `{tracking_number}` and optionally
 *                `{postal_code}`, `{phone}`, `{country}` or `{ship_date}` placeholders
 * - `country`  — ISO country code the carrier is primarily used from, `*` for global
 * - `match`    — optional regular expressions that identify a tracking number as this carrier
 * - `needs`    — extra placeholders the URL requires beyond the tracking number
 *
 * A carrier only earns a `match` pattern when the pattern is genuinely distinctive. Several
 * carriers share the "12 digits" shape; guessing between them would put customers on the wrong
 * carrier's website, which is worse than showing plain text.
 *
 * @see \justinholtweb\trackr\services\Providers
 */

return [
    // North America
    // -------------------------------------------------------------------------
    'ups' => [
        'name' => 'UPS',
        'url' => 'https://www.ups.com/track?loc=en_US&tracknum={tracking_number}',
        'country' => 'US',
        'match' => ['/^1Z[0-9A-Z]{16}$/i', '/^(T\d{10}|\d{9}|\d{26})$/'],
    ],
    'usps' => [
        'name' => 'USPS',
        'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}',
        'country' => 'US',
        'match' => [
            '/^(94|93|92|94|95)\d{20}$/',
            '/^(94|93|92|94|95)\d{18}$/',
            '/^(70|14|23|03)\d{14}$/',
            '/^[A-Z]{2}\d{9}US$/i',
            '/^82\d{8}$/',
        ],
    ],
    'fedex' => [
        'name' => 'FedEx',
        'url' => 'https://www.fedex.com/fedextrack/?trknbr={tracking_number}',
        'country' => 'US',
        'match' => ['/^\d{12}$/', '/^\d{15}$/', '/^\d{20}$/', '/^96\d{20}$/'],
    ],
    'fedex-smartpost' => [
        'name' => 'FedEx SmartPost',
        'url' => 'https://www.fedex.com/fedextrack/?trknbr={tracking_number}',
        'country' => 'US',
    ],
    'dhl-express' => [
        'name' => 'DHL Express',
        'url' => 'https://www.dhl.com/en/express/tracking.html?AWB={tracking_number}&brand=DHL',
        'country' => '*',
        'match' => ['/^\d{10}$/', '/^JJD\d{15,20}$/i'],
    ],
    'dhl-ecommerce' => [
        'name' => 'DHL eCommerce',
        'url' => 'https://webtrack.dhlecs.com/orders?trackingNumber={tracking_number}',
        'country' => 'US',
    ],
    'dhl-global-mail' => [
        'name' => 'DHL Global Mail',
        'url' => 'https://webtrack.dhlglobalmail.com/?trackingnumber={tracking_number}',
        'country' => '*',
        'match' => ['/^GM\d{16,20}$/i'],
    ],
    'ontrac' => [
        'name' => 'OnTrac',
        'url' => 'https://www.ontrac.com/tracking/?number={tracking_number}',
        'country' => 'US',
        'match' => ['/^(C|D)\d{14}$/i'],
    ],
    'lasership' => [
        'name' => 'LaserShip',
        'url' => 'https://www.lasership.com/track/{tracking_number}',
        'country' => 'US',
        'match' => ['/^(1LS|LS)\w{9,}$/i'],
    ],
    'gso' => [
        'name' => 'GLS US (GSO)',
        'url' => 'https://www.gso.com/Tracking?TrackingNumbers={tracking_number}',
        'country' => 'US',
    ],
    'spee-dee' => [
        'name' => 'Spee-Dee Delivery',
        'url' => 'https://speedeedelivery.com/tracking/?tracking={tracking_number}',
        'country' => 'US',
    ],
    'pitney-bowes' => [
        'name' => 'Pitney Bowes',
        'url' => 'https://tracking.pb.com/{tracking_number}',
        'country' => 'US',
    ],
    'newgistics' => [
        'name' => 'Newgistics',
        'url' => 'https://tracking.pb.com/{tracking_number}',
        'country' => 'US',
    ],
    'firstmile' => [
        'name' => 'FirstMile',
        'url' => 'https://firstmile.com/tracking/?tracking_number={tracking_number}',
        'country' => 'US',
    ],
    'amazon-shipping' => [
        'name' => 'Amazon Shipping',
        'url' => 'https://track.amazon.com/tracking/{tracking_number}',
        'country' => '*',
        'match' => ['/^TBA\d{10,}$/i'],
    ],
    'usps-priority-intl' => [
        'name' => 'USPS International',
        'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking_number}',
        'country' => 'US',
    ],
    'canada-post' => [
        'name' => 'Canada Post',
        'url' => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor={tracking_number}',
        'country' => 'CA',
        'match' => ['/^\d{16}$/', '/^[A-Z]{2}\d{9}CA$/i'],
    ],
    'purolator' => [
        'name' => 'Purolator',
        'url' => 'https://www.purolator.com/en/shipping/tracker?pin={tracking_number}',
        'country' => 'CA',
    ],
    'canpar' => [
        'name' => 'Canpar Express',
        'url' => 'https://www.canpar.com/en/track/TrackingAction.do?reference={tracking_number}',
        'country' => 'CA',
    ],
    'loomis' => [
        'name' => 'Loomis Express',
        'url' => 'https://www.loomis-express.com/en/tracking?pin={tracking_number}',
        'country' => 'CA',
    ],
    'estafeta' => [
        'name' => 'Estafeta',
        'url' => 'https://www.estafeta.com/Herramientas/Rastreo?wayBill={tracking_number}',
        'country' => 'MX',
    ],
    'correos-mexico' => [
        'name' => 'Correos de México',
        'url' => 'https://www.correosdemexico.gob.mx/SSLServicios/SeguimientoEnvio/Seguimiento.aspx?guia={tracking_number}',
        'country' => 'MX',
    ],

    // Europe
    // -------------------------------------------------------------------------
    'royal-mail' => [
        'name' => 'Royal Mail',
        'url' => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking_number}',
        'country' => 'GB',
        'match' => ['/^[A-Z]{2}\d{9}GB$/i'],
    ],
    'parcelforce' => [
        'name' => 'Parcelforce Worldwide',
        'url' => 'https://www.parcelforce.com/track-trace?trackNumber={tracking_number}',
        'country' => 'GB',
    ],
    'dpd-uk' => [
        'name' => 'DPD UK',
        'url' => 'https://track.dpd.co.uk/search?reference={tracking_number}',
        'country' => 'GB',
    ],
    'dpd' => [
        'name' => 'DPD',
        'url' => 'https://tracking.dpd.de/status/en_US/parcel/{tracking_number}',
        'country' => 'DE',
    ],
    'hermes-uk' => [
        'name' => 'Evri (Hermes UK)',
        'url' => 'https://www.evri.com/track/parcel/{tracking_number}',
        'country' => 'GB',
    ],
    'yodel' => [
        'name' => 'Yodel',
        'url' => 'https://www.yodel.co.uk/track/{tracking_number}',
        'country' => 'GB',
    ],
    'dhl-parcel-uk' => [
        'name' => 'DHL Parcel UK',
        'url' => 'https://track.dhlparcel.co.uk/?con={tracking_number}',
        'country' => 'GB',
    ],
    'tuffnells' => [
        'name' => 'Tuffnells',
        'url' => 'https://www.tuffnells.co.uk/track-your-parcel/?consignment={tracking_number}',
        'country' => 'GB',
    ],
    'apc-overnight' => [
        'name' => 'APC Overnight',
        'url' => 'https://apc-overnight.com/receiving-a-parcel/track-your-parcel/?parcelNo={tracking_number}',
        'country' => 'GB',
    ],
    'dhl-germany' => [
        'name' => 'DHL Paket (Germany)',
        'url' => 'https://www.dhl.de/en/privatkunden/pakete-empfangen/verfolgen.html?piececode={tracking_number}',
        'country' => 'DE',
    ],
    'deutsche-post' => [
        'name' => 'Deutsche Post',
        'url' => 'https://www.deutschepost.de/sendung/simpleQueryResult.html?form.sendungsnummer={tracking_number}',
        'country' => 'DE',
    ],
    'hermes-germany' => [
        'name' => 'Hermes Germany',
        'url' => 'https://www.myhermes.de/empfangen/sendungsverfolgung/sendungsinformation/#{tracking_number}',
        'country' => 'DE',
    ],
    'gls' => [
        'name' => 'GLS',
        'url' => 'https://gls-group.eu/EU/en/parcel-tracking?match={tracking_number}',
        'country' => 'EU',
    ],
    'dachser' => [
        'name' => 'DACHSER',
        'url' => 'https://elogistics.dachser.com/shipmenttracking/?shipmentNumber={tracking_number}',
        'country' => 'DE',
    ],
    'colissimo' => [
        'name' => 'Colissimo',
        'url' => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking_number}',
        'country' => 'FR',
        'match' => ['/^\d{2}[A-Z]{2}\d{9}$/i'],
    ],
    'chronopost' => [
        'name' => 'Chronopost',
        'url' => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT={tracking_number}',
        'country' => 'FR',
    ],
    'mondial-relay' => [
        'name' => 'Mondial Relay',
        'url' => 'https://www.mondialrelay.fr/suivi-de-colis?numeroExpedition={tracking_number}&codePostal={postal_code}',
        'country' => 'FR',
        'needs' => ['postal_code'],
    ],
    'colis-prive' => [
        'name' => 'Colis Privé',
        'url' => 'https://www.colisprive.fr/moncolis/pages/detailColis.aspx?numColis={tracking_number}',
        'country' => 'FR',
    ],
    'correos' => [
        'name' => 'Correos (Spain)',
        'url' => 'https://www.correos.es/es/es/herramientas/localizador/envios/detalle?tracking-number={tracking_number}',
        'country' => 'ES',
    ],
    'seur' => [
        'name' => 'SEUR',
        'url' => 'https://www.seur.com/livetracking/?segOnlineIdentificador={tracking_number}',
        'country' => 'ES',
    ],
    'mrw' => [
        'name' => 'MRW',
        'url' => 'https://www.mrw.es/seguimiento_envios/MRW_resultado_consultas.asp?modo=nacional&envio={tracking_number}',
        'country' => 'ES',
    ],
    'nacex' => [
        'name' => 'NACEX',
        'url' => 'https://www.nacex.es/seguimientoDetalle.do?agencia_origen=&numero_albaran={tracking_number}',
        'country' => 'ES',
    ],
    'poste-italiane' => [
        'name' => 'Poste Italiane',
        'url' => 'https://www.poste.it/cerca/index.html#/risultati-spedizioni/{tracking_number}',
        'country' => 'IT',
    ],
    'brt' => [
        'name' => 'BRT (Bartolini)',
        'url' => 'https://vas.brt.it/vas/sped_det_show.hsm?referer=sped_numspe_par.htm&Nspediz={tracking_number}',
        'country' => 'IT',
    ],
    'sda' => [
        'name' => 'SDA',
        'url' => 'https://www.sda.it/wps/portal/Servizi_online/ricerca_spedizioni?locale=it&tracing.letteraVettura={tracking_number}',
        'country' => 'IT',
    ],
    'postnl' => [
        'name' => 'PostNL',
        'url' => 'https://postnl.nl/tracktrace/?B={tracking_number}&P={postal_code}&D={country}&T=C',
        'country' => 'NL',
        'needs' => ['postal_code', 'country'],
        'match' => ['/^3S[A-Z0-9]{8,}$/i'],
    ],
    'dhl-netherlands' => [
        'name' => 'DHL Parcel NL',
        'url' => 'https://www.dhlparcel.nl/en/consumer/track-and-trace?tt={tracking_number}',
        'country' => 'NL',
    ],
    'bpost' => [
        'name' => 'bpost',
        'url' => 'https://track.bpost.cloud/btr/web/#/search?itemCode={tracking_number}',
        'country' => 'BE',
    ],
    'posti' => [
        'name' => 'Posti',
        'url' => 'https://www.posti.fi/en/tracking#/lahetys/{tracking_number}',
        'country' => 'FI',
    ],
    'postnord' => [
        'name' => 'PostNord',
        'url' => 'https://www.postnord.se/en/track-and-trace?shipmentId={tracking_number}',
        'country' => 'SE',
    ],
    'bring' => [
        'name' => 'Bring',
        'url' => 'https://sporing.bring.no/sporing.html?q={tracking_number}',
        'country' => 'NO',
    ],
    'gls-denmark' => [
        'name' => 'GLS Denmark',
        'url' => 'https://gls-group.eu/DK/da/find-pakke?match={tracking_number}',
        'country' => 'DK',
    ],
    'swiss-post' => [
        'name' => 'Swiss Post',
        'url' => 'https://service.post.ch/ekp-web/ui/entry/search/{tracking_number}',
        'country' => 'CH',
    ],
    'austrian-post' => [
        'name' => 'Austrian Post',
        'url' => 'https://www.post.at/en/track?snr={tracking_number}',
        'country' => 'AT',
    ],
    'inpost' => [
        'name' => 'InPost',
        'url' => 'https://inpost.pl/en/find-parcel?number={tracking_number}',
        'country' => 'PL',
    ],
    'poczta-polska' => [
        'name' => 'Poczta Polska',
        'url' => 'https://emonitoring.poczta-polska.pl/?numer={tracking_number}',
        'country' => 'PL',
    ],
    'ceska-posta' => [
        'name' => 'Česká pošta',
        'url' => 'https://www.postaonline.cz/en/trackandtrace/-/zasilka/cislo?parcelNumbers={tracking_number}',
        'country' => 'CZ',
    ],
    'packeta' => [
        'name' => 'Packeta (Zásilkovna)',
        'url' => 'https://tracking.packeta.com/en/?id={tracking_number}',
        'country' => 'CZ',
    ],
    'an-post' => [
        'name' => 'An Post',
        'url' => 'https://www.anpost.com/Post-Parcels/Track/History?item={tracking_number}',
        'country' => 'IE',
    ],
    'ctt' => [
        'name' => 'CTT (Portugal)',
        'url' => 'https://appserver.ctt.pt/CustomerArea/PublicArea_Detail?ObjectCodeInput={tracking_number}',
        'country' => 'PT',
    ],
    'elta' => [
        'name' => 'ELTA (Greece)',
        'url' => 'https://itemsearch.elta.gr/en-GB/Query/Direct/{tracking_number}',
        'country' => 'GR',
    ],
    'ptt' => [
        'name' => 'PTT (Turkey)',
        'url' => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q={tracking_number}',
        'country' => 'TR',
    ],
    'russian-post' => [
        'name' => 'Russian Post',
        'url' => 'https://www.pochta.ru/tracking#{tracking_number}',
        'country' => 'RU',
    ],
    'nova-poshta' => [
        'name' => 'Nova Poshta',
        'url' => 'https://novaposhta.ua/en/tracking/?cargo_number={tracking_number}',
        'country' => 'UA',
    ],

    // Asia-Pacific
    // -------------------------------------------------------------------------
    'australia-post' => [
        'name' => 'Australia Post',
        'url' => 'https://auspost.com.au/mypost/track/details/{tracking_number}',
        'country' => 'AU',
    ],
    'startrack' => [
        'name' => 'StarTrack',
        'url' => 'https://startrack.com.au/track-trace?id={tracking_number}',
        'country' => 'AU',
    ],
    'toll' => [
        'name' => 'Toll Group',
        'url' => 'https://www.tollgroup.com/toll-track-and-trace?id={tracking_number}',
        'country' => 'AU',
    ],
    'sendle' => [
        'name' => 'Sendle',
        'url' => 'https://track.sendle.com/tracking?ref={tracking_number}',
        'country' => 'AU',
    ],
    'aramex' => [
        'name' => 'Aramex',
        'url' => 'https://www.aramex.com/us/en/track/results?mode=0&ShipmentNumber={tracking_number}',
        'country' => '*',
    ],
    'nz-post' => [
        'name' => 'New Zealand Post',
        'url' => 'https://www.nzpost.co.nz/tools/tracking/item/{tracking_number}',
        'country' => 'NZ',
    ],
    'japan-post' => [
        'name' => 'Japan Post',
        'url' => 'https://trackings.post.japanpost.jp/services/srv/search/direct?searchKind=S002&reqCodeNo1={tracking_number}&locale=en',
        'country' => 'JP',
    ],
    'yamato' => [
        'name' => 'Yamato Transport',
        'url' => 'https://toi.kuronekoyamato.co.jp/cgi-bin/tneko?number01={tracking_number}',
        'country' => 'JP',
    ],
    'sagawa' => [
        'name' => 'Sagawa Express',
        'url' => 'https://k2k.sagawa-exp.co.jp/p/sagawa/web/okurijoinput.jsp?okurijoNo={tracking_number}',
        'country' => 'JP',
    ],
    'korea-post' => [
        'name' => 'Korea Post',
        'url' => 'https://service.epost.go.kr/trace.RetrieveEmsRigiTraceList.comm?ems_gubun=E&POST_CODE={tracking_number}',
        'country' => 'KR',
    ],
    'cj-logistics' => [
        'name' => 'CJ Logistics',
        'url' => 'https://www.cjlogistics.com/en/tool/parcel/tracking?gnbInvcNo={tracking_number}',
        'country' => 'KR',
    ],
    'china-post' => [
        'name' => 'China Post',
        'url' => 'https://www.17track.net/en/track?nums={tracking_number}',
        'country' => 'CN',
        'match' => ['/^[A-Z]{2}\d{9}CN$/i'],
    ],
    'china-ems' => [
        'name' => 'China EMS',
        'url' => 'https://www.ems.com.cn/english/queryOrder?mailNum={tracking_number}',
        'country' => 'CN',
    ],
    'sf-express' => [
        'name' => 'SF Express',
        'url' => 'https://www.sf-express.com/we/ow/chn/en/waybill/detail/{tracking_number}',
        'country' => 'CN',
        'match' => ['/^SF\d{12,}$/i'],
    ],
    'yunexpress' => [
        'name' => 'YunExpress',
        'url' => 'https://www.yuntrack.com/parcelTracking?id={tracking_number}',
        'country' => 'CN',
    ],
    'cainiao' => [
        'name' => 'Cainiao',
        'url' => 'https://global.cainiao.com/newDetail.htm?mailNoList={tracking_number}',
        'country' => 'CN',
        'match' => ['/^LP\d{14,}$/i'],
    ],
    '4px' => [
        'name' => '4PX',
        'url' => 'https://track.4px.com/query/{tracking_number}',
        'country' => 'CN',
    ],
    'hongkong-post' => [
        'name' => 'Hongkong Post',
        'url' => 'https://webapp.hongkongpost.hk/en/mail_tracking/index.html?tracking_number={tracking_number}',
        'country' => 'HK',
    ],
    'singapore-post' => [
        'name' => 'Singapore Post',
        'url' => 'https://www.singpost.com/track-items?track_number={tracking_number}',
        'country' => 'SG',
    ],
    'ninja-van' => [
        'name' => 'Ninja Van',
        'url' => 'https://www.ninjavan.co/en-sg/tracking?id={tracking_number}',
        'country' => 'SG',
    ],
    'j-t-express' => [
        'name' => 'J&T Express',
        'url' => 'https://www.jtexpress.sg/index/query/gzquery.html?bills={tracking_number}',
        'country' => 'SG',
    ],
    'india-post' => [
        'name' => 'India Post',
        'url' => 'https://www.indiapost.gov.in/_layouts/15/DOP.Portal.Tracking/TrackConsignment.aspx?logicalname={tracking_number}',
        'country' => 'IN',
        'match' => ['/^[A-Z]{2}\d{9}IN$/i'],
    ],
    'delhivery' => [
        'name' => 'Delhivery',
        'url' => 'https://www.delhivery.com/track/package/{tracking_number}',
        'country' => 'IN',
    ],
    'bluedart' => [
        'name' => 'Blue Dart',
        'url' => 'https://www.bluedart.com/tracking/{tracking_number}',
        'country' => 'IN',
    ],
    'dtdc' => [
        'name' => 'DTDC',
        'url' => 'https://www.dtdc.in/tracking/tracking_results.asp?strCnno={tracking_number}',
        'country' => 'IN',
    ],
    'pos-malaysia' => [
        'name' => 'Pos Malaysia',
        'url' => 'https://send.pos.com.my/tracking?tracking_number={tracking_number}',
        'country' => 'MY',
    ],
    'thailand-post' => [
        'name' => 'Thailand Post',
        'url' => 'https://track.thailandpost.co.th/?trackNumber={tracking_number}',
        'country' => 'TH',
    ],
    'vietnam-post' => [
        'name' => 'Vietnam Post',
        'url' => 'https://www.vnpost.vn/en/tracking?key={tracking_number}',
        'country' => 'VN',
    ],
    'jne' => [
        'name' => 'JNE',
        'url' => 'https://www.jne.co.id/tracking-package?code={tracking_number}',
        'country' => 'ID',
    ],

    // South America, Africa, Middle East
    // -------------------------------------------------------------------------
    'correios' => [
        'name' => 'Correios (Brazil)',
        'url' => 'https://rastreamento.correios.com.br/app/index.php?objeto={tracking_number}',
        'country' => 'BR',
        'match' => ['/^[A-Z]{2}\d{9}BR$/i'],
    ],
    'jadlog' => [
        'name' => 'Jadlog',
        'url' => 'https://www.jadlog.com.br/tracking?cte={tracking_number}',
        'country' => 'BR',
    ],
    'oca' => [
        'name' => 'OCA (Argentina)',
        'url' => 'https://www.oca.com.ar/Busquedas/Envios?numeroEnvio={tracking_number}',
        'country' => 'AR',
    ],
    'chilexpress' => [
        'name' => 'Chilexpress',
        'url' => 'https://www.chilexpress.cl/Views/ChilexpressCL/Resultado-busqueda.aspx?DATA={tracking_number}',
        'country' => 'CL',
    ],
    'servientrega' => [
        'name' => 'Servientrega',
        'url' => 'https://www.servientrega.com/wps/portal/rastreo-envio?guia={tracking_number}',
        'country' => 'CO',
    ],
    'south-african-post' => [
        'name' => 'South African Post Office',
        'url' => 'https://www.postoffice.co.za/tools/trackandtrace.html?id={tracking_number}',
        'country' => 'ZA',
    ],
    'ram-couriers' => [
        'name' => 'RAM Hand-to-Hand Couriers',
        'url' => 'https://www.ram.co.za/track-a-shipment/?waybill={tracking_number}',
        'country' => 'ZA',
    ],
    'the-courier-guy' => [
        'name' => 'The Courier Guy',
        'url' => 'https://portal.thecourierguy.co.za/track?ref={tracking_number}',
        'country' => 'ZA',
    ],
    'emirates-post' => [
        'name' => 'Emirates Post',
        'url' => 'https://www.emiratespost.ae/track?trackingnumber={tracking_number}',
        'country' => 'AE',
    ],
    'israel-post' => [
        'name' => 'Israel Post',
        'url' => 'https://mypost.israelpost.co.il/itemtrace?itemcode={tracking_number}',
        'country' => 'IL',
    ],

    // Freight, print-on-demand and aggregators
    // -------------------------------------------------------------------------
    'xpo' => [
        'name' => 'XPO Logistics',
        'url' => 'https://www.xpo.com/track/?proNumber={tracking_number}',
        'country' => 'US',
    ],
    'estes' => [
        'name' => 'Estes Express',
        'url' => 'https://www.estes-express.com/myestes/shipment-tracking/?type=PRO&query={tracking_number}',
        'country' => 'US',
    ],
    'old-dominion' => [
        'name' => 'Old Dominion Freight Line',
        'url' => 'https://www.odfl.com/us/en/tools/tracking.html?pro={tracking_number}',
        'country' => 'US',
    ],
    'saia' => [
        'name' => 'Saia LTL Freight',
        'url' => 'https://www.saia.com/track/details?pro={tracking_number}',
        'country' => 'US',
    ],
    'rl-carriers' => [
        'name' => 'R+L Carriers',
        'url' => 'https://www2.rlcarriers.com/freight/shipping/shipment-tracing?pro={tracking_number}&docType=PRO',
        'country' => 'US',
    ],
    'abf' => [
        'name' => 'ABF Freight',
        'url' => 'https://arcb.com/tools/tracking.html#/{tracking_number}',
        'country' => 'US',
    ],
    'printful' => [
        'name' => 'Printful',
        'url' => 'https://www.printful.com/dashboard/track?tracking={tracking_number}',
        'country' => '*',
    ],
    'aftership' => [
        'name' => 'AfterShip',
        'url' => 'https://track.aftership.com/{tracking_number}',
        'country' => '*',
    ],
    '17track' => [
        'name' => '17TRACK',
        'url' => 'https://www.17track.net/en/track?nums={tracking_number}',
        'country' => '*',
    ],
    'parcelsapp' => [
        'name' => 'Parcels App',
        'url' => 'https://parcelsapp.com/en/tracking/{tracking_number}',
        'country' => '*',
    ],
    'local-delivery' => [
        'name' => 'Local delivery',
        'url' => '',
        'country' => '*',
    ],
    'customer-pickup' => [
        'name' => 'Customer pickup',
        'url' => '',
        'country' => '*',
    ],
    'other' => [
        'name' => 'Other',
        'url' => '',
        'country' => '*',
    ],
];
