/**
 * Seed copy for The Franchise Buddy landing, ported verbatim from the design
 * handoff (ui_kits/landing/landing-data.v2.js).
 *
 * FR is authored and is the fallback. EN and AR are authored. NL / DE / PL / UK / RU
 * are deliberately absent so they exercise the real fallback path — add rows through
 * the back office's translation editor as translators deliver.
 *
 * AR is translator-review quality, not final. See README.
 */

export const AUTHORED_LOCALES = ['fr', 'en', 'ar'] as const;
export type AuthoredLocale = (typeof AUTHORED_LOCALES)[number];

/** tfb_languages rows. */
export const LANGUAGES = [
  { code: 'fr', name: 'Français', isRtl: false, isDefault: true, sortOrder: 1 },
  { code: 'en', name: 'English', isRtl: false, isDefault: false, sortOrder: 2 },
  { code: 'nl', name: 'Nederlands', isRtl: false, isDefault: false, sortOrder: 3 },
  { code: 'de', name: 'Deutsch', isRtl: false, isDefault: false, sortOrder: 4 },
  { code: 'pl', name: 'Polski', isRtl: false, isDefault: false, sortOrder: 5 },
  { code: 'uk', name: 'Українська', isRtl: false, isDefault: false, sortOrder: 6 },
  { code: 'ru', name: 'Русский', isRtl: false, isDefault: false, sortOrder: 7 },
  { code: 'ar', name: 'العربية', isRtl: true, isDefault: false, sortOrder: 8 },
];

/** tfb_sections — the order the landing renders in. */
export const SECTIONS = [
  { key: 'hero', type: 'hero', sortOrder: 1, isActive: true },
  // Ce que le franchiseur vit, avant ce que l'outil fait. Les paires
  // problème / réponse sont dans tfb_translations (pain.1p, pain.1a…).
  { key: 'pains', type: 'pains', sortOrder: 2, isActive: true },
  { key: 'modules', type: 'module-grid', sortOrder: 3, isActive: true },
  { key: 'steps', type: 'steps', sortOrder: 4, isActive: true },
  { key: 'diff', type: 'feature-grid', sortOrder: 5, isActive: true },
  { key: 'pricing', type: 'pricing', sortOrder: 6, isActive: true },
  { key: 'testimonials', type: 'quotes', sortOrder: 7, isActive: false },
  { key: 'contact', type: 'form', sortOrder: 8, isActive: true },
  // Désactivée tant qu'aucune référence client n'est autorisée à être citée.
  // Une bande de logos vide vaut mieux qu'une bande de logos inventés.
  { key: 'brands', type: 'logo-strip', sortOrder: 9, isActive: false },
];

/**
 * tfb_brands — vide, et c'est délibéré.
 *
 * La maquette listait six enseignes (Belleville Bistro, Kebab House, Sushi Loop,
 * Café Nord, Pasta Bar, Green Bowl) : aucune n'est cliente, toutes étaient
 * inventées. Un faux logo client sur une page commerciale n'est pas un
 * placeholder, c'est une affirmation fausse.
 *
 * Ajoutez les vraies références par le back-office, une fois leur accord obtenu,
 * puis réactivez la section `brands`.
 */
export const BRANDS: { slug: string; name: string; sortOrder: number }[] = [];

/**
 * tfb_modules — vide, et c'est le point.
 *
 * La maquette posait sept modules (shop, invoicing, offers, scan, marketing,
 * ceobot, pwa) dont aucun n'existait : ni dépôt, ni écran, ni ligne de code. Les
 * modules réels viennent maintenant des dépôts eux-mêmes, chacun publiant sa
 * fiche `.tfb/module.json` que `npm run sync:modules` charge en base.
 *
 * Conséquence assumée : après `npm run seed`, la grille des modules est vide.
 * Elle se remplit à la première sync — voir README, « Rester à jour ».
 */
export const MODULES: {
  key: string; slug: string; icon: string; moduleGroup: string;
  repo: string; isNew: boolean; sortOrder: number; shots: number;
}[] = [];

/**
 * tfb_plans. `amount` est en centimes ; NULL affiche « Sur devis ».
 *
 * Les trois montants de la maquette (89 € et 149 € par point de vente) n'ont
 * jamais été validés commercialement : ils sont donc à NULL. Les trois paliers
 * s'affichent « Sur devis » et renvoient au formulaire de contact, ce qui est
 * vrai, plutôt qu'un prix inventé, qui ne l'est pas.
 *
 * Renseignez les montants ici, ou dans le back-office (Tarifs), quand ils sont
 * arrêtés — et le `stripe_price_id` correspondant pour activer le paiement.
 */
export const PLANS = [
  { key: 'starter', amount: null as number | null, currency: 'EUR', interval: 'month', isFeatured: false, stripePriceId: null, sortOrder: 1 },
  { key: 'pro', amount: null as number | null, currency: 'EUR', interval: 'month', isFeatured: true, stripePriceId: null, sortOrder: 2 },
  { key: 'enterprise', amount: null as number | null, currency: 'EUR', interval: 'year', isFeatured: false, stripePriceId: null, sortOrder: 3 },
];

/**
 * tfb_translations, entity_type='ui', entity_id=0. The `field` is the dotted key the
 * landing asks for. Multi-value fields are pipe-joined.
 */
export const UI_STRINGS: Record<AuthoredLocale, Record<string, string>> = {
  fr: {
    'pain.eyebrow': 'Le vrai enjeu',
    'pain.title': 'Un réseau ne se vend pas sur le talent de son fondateur',
    'pain.lead': 'Il se reprend — par un nouveau franchisé, un nouveau directeur, un repreneur. Ce qui n’est écrit nulle part ne se transmet pas, et ce qui ne se transmet pas ne se valorise pas.',
    'pain.1p': '« Le savoir-faire est dans la tête de deux personnes. »',
    'pain.1a': 'Les procédures deviennent des checklists exécutées en magasin, horodatées et attribuées. Ce qui est fait est prouvable, et ce qui s’apprend s’apprend sur l’outil.',
    'pain.2p': '« Chaque point de vente fait un peu à sa façon. »',
    'pain.2a': 'Le catalogue, les prix et les formules sont décidés au siège et appliqués partout. Ce qui est local reste local — et c’est vous qui décidez de la frontière.',
    'pain.3p': '« Je découvre les chiffres à la fin du mois, quand ils arrivent. »',
    'pain.3a': 'Commandes, préparation, livraisons et incidents remontent au fil de l’eau, consolidés par enseigne. Vous arrêtez de relancer pour savoir où vous en êtes.',
    'pain.4p': '« Ouvrir un point de vente prend six mois de bricolage. »',
    'pain.4a': 'Un magasin de plus est une fiche à créer : il hérite du catalogue, des règles et des droits du réseau. La duplication est le fonctionnement normal, pas un projet.',
    'pain.5p': '« À la revente, on me demandera des preuves, pas des intentions. »',
    'pain.5a': 'Un historique continu par magasin : vendu, contrôlé, livré, réclamé. C’est ce qu’un repreneur ou un investisseur regarde — et c’est ce qui se valorise.',
    'nav.modules': 'Modules', 'nav.pricing': 'Tarifs', 'nav.contact': 'Contact', 'nav.signin': 'Connexion',
    'cta.demo': 'Demander une démo', 'cta.start': 'Commencer',
    'hero.eyebrow': 'Franchise et chaînes de magasins · Outils d’exploitation',
    'hero.title': 'Ce que vaut votre réseau, c’est ce que vous pouvez transmettre.',
    'hero.subtitle': 'Un savoir-faire qui vit dans la tête de deux personnes ne se duplique pas, ne se délègue pas, et ne se revend pas. Huit modules qui mettent votre exploitation par écrit : ce que chacun doit faire, ce qui a été fait, et ce que ça donne.',
    'hero.proof1': 'Ce qui est fait est prouvable', 'hero.proof2': 'Des écrans réels, pas des maquettes',
    'brands.title': 'Ils pilotent leur réseau avec ces modules',
    'modules.eyebrow': 'Modules', 'modules.title': 'Un module par métier du réseau',
    'modules.lead': 'Le siège décide, le terrain exécute, l’outil garde la trace. Chaque module publie sa fiche depuis son dépôt : ce que vous lisez ici décrit ce qui tourne, captures comprises.',
    'modules.link': 'Découvrir le module', 'modules.new': 'Nouveau',
    'modules.all': 'Tous les modules', 'modules.count': 'modules', 'modules.newcount': 'nouveaux',
    'detail.back': 'Tous les modules', 'detail.connected': 'Modules connectés', 'detail.gain': 'Gain constaté',
    'detail.setup': 'Mise en route', 'detail.week': 'semaine', 'detail.sheet': 'Recevoir la fiche', 'detail.included': 'Ce que fait le module',
    'detail.overview': 'Le module en deux mots', 'detail.functions': 'Fonctions', 'detail.functions.title': 'Chaque fonction, écran à l’appui',
    'group.ventes': 'Ventes', 'group.finance': 'Finance', 'group.marketing': 'Marketing',
    'group.logistique': 'Logistique', 'group.assistance': 'Assistance', 'group.terrain': 'Terrain',
    'group.pilotage': 'Pilotage',
    'shot.alt': 'Capture du module',
    'mock.brand': 'TFB Admin · jeu de démonstration', 'mock.stores': '64 PDV',
    'mock.collected': 'Encaissé (juil.)', 'mock.licenses': 'Abonnements', 'mock.vsjune': 'vs. juin',
    'mock.thismonth': 'ce mois', 'mock.active': 'Actif',
    'mock.collected.value': '418 k€', 'mock.collected.delta': '+6,4%',
    'mock.licenses.value': '128', 'mock.licenses.delta': '+6',
    'steps.eyebrow': 'La mise en route', 'steps.title': 'Mettre votre exploitation par écrit, en quatre étapes',
    'steps.1t': 'On écrit votre standard', 'steps.1d': 'Vos enseignes, vos magasins, vos règles : qui décide quoi, qui exécute quoi, qui voit quoi.',
    'steps.2t': 'On reprend vos données', 'steps.2d': 'Catalogue, tarifs, clients professionnels, zones de livraison : importés, pas ressaisis.',
    'steps.3t': 'Le terrain travaille dessus', 'steps.3d': 'Cuisine, chauffeurs, animateurs réseau : chacun son écran, tous sur la même donnée.',
    'steps.4t': 'Vous pilotez sur des faits', 'steps.4d': 'Vendu, préparé, livré, contrôlé, réclamé. Consolidé par enseigne, au fil de l’eau.',
    'diff.eyebrow': 'Ce que ça change', 'diff.title': 'Ce qui rend un réseau duplicable',
    'diff.1t': 'Le standard s’applique, il ne se rappelle pas', 'diff.1d': 'Catalogue, prix et formules décidés au siège, appliqués partout. Le franchisé garde ce qui est local, pas le reste.',
    'diff.2t': 'Rien ne se ressaisit entre deux étapes', 'diff.2d': 'Le catalogue alimente le webshop, le webshop alimente la préparation, la préparation alimente la tournée. Chaque ressaisie est une occasion de diverger.',
    'diff.3t': 'Un magasin de plus, c’est une fiche', 'diff.3d': 'Il hérite du catalogue, des règles et des droits. Ouvrir ne veut plus dire tout réinstaller.',
    'diff.4t': 'Huit langues, arabe en miroir', 'diff.4d': 'Toutes les chaînes viennent de la base, jamais du code. Un réseau qui passe une frontière n’attend pas une nouvelle version.',
    'pricing.eyebrow': 'Tarifs', 'pricing.title': 'Par point de vente, pas par utilisateur',
    'pricing.lead': 'Un tarif par point de vente, pas par utilisateur : invitez les franchisés, les managers et les équipes sans compter les sièges.',
    'pricing.recommended': 'Recommandé', 'pricing.month': '/ mois par point de vente', 'pricing.custom': 'Sur devis',
    'plan.cta': 'Choisir ce plan', 'plan.cta.custom': 'Parler à un expert',
    'contact.eyebrow': 'Contact', 'contact.title': 'Parlons de votre réseau',
    'contact.lead': 'Combien de points de vente, combien d’enseignes, et qu’est-ce qui vous échappe aujourd’hui ? Nous revenons sous un jour ouvré avec une démo sur vos cas.',
    'contact.name': 'Nom', 'contact.email': 'E-mail professionnel', 'contact.company': 'Société', 'contact.message': 'Message',
    'contact.send': 'Envoyer', 'contact.sent': 'Message reçu', 'contact.sentmsg': 'Nous répondons sous un jour ouvré.',
    'contact.error': 'Envoi impossible', 'contact.errormsg': 'Vérifiez votre e-mail et réessayez.',
    'contact.mail': 'contact@franchisebuddy.eu', 'contact.hours': 'Lun — ven, 9h — 18h CET',
    'footer.product': 'Produit', 'footer.company': 'Société', 'footer.legal': 'Mentions légales', 'footer.langs': 'Langues',
    'footer.tagline': 'ERP SaaS pour les franchises et chaînes de magasins en restauration et retail.',
    'footer.company.items': 'À propos|Sécurité|Contact',
    'footer.legal.items': 'Mentions légales|CGU|Sous-traitants',
    'footer.rights': '© 2026 The Franchise Buddy — ERP SaaS',
    'demo.title': 'Demander une démo', 'demo.desc': 'Trente minutes, vos données, aucune diapositive.',
    'demo.locations': 'Points de vente', 'demo.cancel': 'Annuler', 'demo.confirm': 'Demander un créneau',
    'checkout.title': 'Redirection vers Stripe', 'checkout.stub': 'Stripe n’est pas encore configuré — aucune session créée.',
    'brand.name': 'The Franchise Buddy',
  },
  en: {
    'pain.eyebrow': 'The real stake',
    'pain.title': 'A network is not sold on its founder\'s talent',
    'pain.lead': 'It is taken over — by a new franchisee, a new director, a buyer. What is written down nowhere cannot be handed over, and what cannot be handed over cannot be valued.',
    'pain.1p': '“The know-how is in two people\'s heads.”',
    'pain.1a': 'Procedures become checklists executed in store, timestamped and attributed. What was done is provable, and what has to be learned is learned on the tool.',
    'pain.2p': '“Every store does it slightly its own way.”',
    'pain.2a': 'Catalogue, prices and bundles are decided at head office and applied everywhere. What is local stays local — and you draw that line.',
    'pain.3p': '“I find out the numbers at month end, when they arrive.”',
    'pain.3a': 'Orders, preparation, deliveries and incidents come up as they happen, consolidated per brand. You stop chasing people to know where you stand.',
    'pain.4p': '“Opening a store takes six months of improvising.”',
    'pain.4a': 'One more store is one more record: it inherits the network\'s catalogue, rules and rights. Duplication is the normal mode, not a project.',
    'pain.5p': '“At resale, they will ask me for evidence, not intentions.”',
    'pain.5a': 'A continuous per-store history: sold, checked, delivered, disputed. That is what a buyer or an investor looks at — and what gets valued.',
    'nav.modules': 'Modules', 'nav.pricing': 'Pricing', 'nav.contact': 'Contact', 'nav.signin': 'Sign in',
    'cta.demo': 'Book a demo', 'cta.start': 'Get started',
    'hero.eyebrow': 'Franchise networks and store chains · Operations tooling',
    'hero.title': 'What your network is worth is what you can hand over.',
    'hero.subtitle': 'Know-how that lives in two people\'s heads cannot be duplicated, delegated, or sold. Eight modules that put your operations in writing: what each person must do, what was done, and what it produced.',
    'hero.proof1': 'What was done is provable', 'hero.proof2': 'Real screens, not mockups',
    'brands.title': 'Networks running on these modules',
    'modules.eyebrow': 'Modules', 'modules.title': 'One module per job in the network',
    'modules.lead': 'Head office decides, the field executes, the tool keeps the record. Every module publishes its fiche from its own repository: what you read here describes what runs, screenshots included.',
    'modules.link': 'Explore the module', 'modules.new': 'New',
    'modules.all': 'All modules', 'modules.count': 'modules', 'modules.newcount': 'new',
    'detail.back': 'All modules', 'detail.connected': 'Connected modules', 'detail.gain': 'Measured gain',
    'detail.setup': 'Setup', 'detail.week': 'week', 'detail.sheet': 'Get the datasheet', 'detail.included': 'What the module does',
    'detail.overview': 'The module in short', 'detail.functions': 'Functions', 'detail.functions.title': 'Every function, with the screen behind it',
    'group.ventes': 'Sales', 'group.finance': 'Finance', 'group.marketing': 'Marketing',
    'group.logistique': 'Logistics', 'group.assistance': 'Support', 'group.terrain': 'Field',
    'group.pilotage': 'Steering',
    'shot.alt': 'Module screenshot',
    'mock.brand': 'TFB Admin · demo dataset', 'mock.stores': '64 stores',
    'mock.collected': 'Collected (Jul)', 'mock.licenses': 'Subscriptions', 'mock.vsjune': 'vs. June',
    'mock.thismonth': 'this month', 'mock.active': 'Active',
    'steps.eyebrow': 'Getting started', 'steps.title': 'Putting your operations in writing, in four steps',
    'steps.1t': 'We write down your standard', 'steps.1d': 'Your brands, your stores, your rules: who decides what, who executes what, who sees what.',
    'steps.2t': 'We bring your data over', 'steps.2d': 'Catalogue, prices, business customers, delivery areas: imported, not retyped.',
    'steps.3t': 'The field works on it', 'steps.3d': 'Kitchen, drivers, network consultants: each with their screen, all on the same data.',
    'steps.4t': 'You steer on facts', 'steps.4d': 'Sold, prepared, delivered, checked, disputed. Consolidated per brand, as it happens.',
    'diff.eyebrow': 'What it changes', 'diff.title': 'What makes a network duplicable',
    'diff.1t': 'The standard applies, it isn\'t recalled', 'diff.1d': 'Catalogue, prices and bundles decided at head office and applied everywhere. The franchisee keeps what is local, not the rest.',
    'diff.2t': 'Nothing is retyped between two steps', 'diff.2d': 'The catalogue feeds the webshop, the webshop feeds preparation, preparation feeds the delivery tour. Every retype is a chance to diverge.',
    'diff.3t': 'One more store is one more record', 'diff.3d': 'It inherits the catalogue, the rules and the rights. Opening no longer means installing everything again.',
    'diff.4t': 'Eight languages, Arabic mirrored', 'diff.4d': 'Every string comes from the database, never from the code. A network crossing a border doesn\'t wait for a new release.',
    'pricing.eyebrow': 'Pricing', 'pricing.title': 'Per store, not per seat',
    'pricing.lead': 'Priced per store, not per user: invite the franchisees, the managers and the teams without counting seats.',
    'pricing.recommended': 'Recommended', 'pricing.month': '/ month per store', 'pricing.custom': 'Custom',
    'plan.cta': 'Choose this plan', 'plan.cta.custom': 'Talk to an expert',
    'contact.eyebrow': 'Contact', 'contact.title': "Let's talk about your network",
    'contact.lead': 'How many stores, how many brands, and what is getting away from you today? We come back within one working day with a demo on your cases.',
    'contact.name': 'Name', 'contact.email': 'Work email', 'contact.company': 'Company', 'contact.message': 'Message',
    'contact.send': 'Send', 'contact.sent': 'Message received', 'contact.sentmsg': 'We reply within one business day.',
    'contact.error': 'Could not send', 'contact.errormsg': 'Check your email address and try again.',
    'contact.mail': 'contact@franchisebuddy.eu', 'contact.hours': 'Mon — Fri, 9am — 6pm CET',
    'footer.product': 'Product', 'footer.company': 'Company', 'footer.legal': 'Legal', 'footer.langs': 'Languages',
    'footer.tagline': 'ERP SaaS for franchises and store chains in food and retail.',
    'footer.company.items': 'About|Security|Contact',
    'footer.legal.items': 'Legal notice|Terms|Sub-processors',
    'footer.rights': '© 2026 The Franchise Buddy — ERP SaaS',
    'demo.title': 'Book a demo', 'demo.desc': 'Thirty minutes, your data, no slides.',
    'demo.locations': 'Stores', 'demo.cancel': 'Cancel', 'demo.confirm': 'Request a slot',
    'checkout.title': 'Redirecting to Stripe', 'checkout.stub': 'Stripe is not configured yet — no session was created.',
    'brand.name': 'The Franchise Buddy',
  },
  ar: {
    'pain.eyebrow': 'الرهان الحقيقي',
    'pain.title': 'الشبكة لا تُباع بموهبة مؤسّسها',
    'pain.lead': 'بل تُستلم — من صاحب امتياز جديد أو مدير جديد أو مشترٍ. ما لا يُكتب لا يُسلَّم، وما لا يُسلَّم لا تُقدَّر قيمته.',
    'pain.1p': '«المعرفة في رأسي شخصين.»',
    'pain.1a': 'الإجراءات تصير قوائم مهام تُنفَّذ في الفرع، بختم زمني واسم منفّذها. ما أُنجز يمكن إثباته، وما يُتعلَّم يُتعلَّم على الأداة.',
    'pain.2p': '«كل فرع يعمل بطريقته قليلًا.»',
    'pain.2a': 'الكتالوج والأسعار والصيغ تُقرَّر في المقر وتُطبَّق في كل مكان. وما هو محلي يبقى محليًا — وأنت من يرسم الحد.',
    'pain.3p': '«أكتشف الأرقام في آخر الشهر، إن وصلت.»',
    'pain.3a': 'الطلبات والتحضير والتسليمات والحوادث تصعد أولًا بأول، مجمّعة لكل علامة. تتوقف عن المطالبة لتعرف أين أنت.',
    'pain.4p': '«افتتاح فرع يستغرق ستة أشهر من الارتجال.»',
    'pain.4a': 'فرع إضافي هو بطاقة تُنشأ: يرث كتالوج الشبكة وقواعدها وصلاحياتها. الاستنساخ هو الوضع الطبيعي لا مشروع قائم بذاته.',
    'pain.5p': '«عند البيع سيطلبون مني أدلة لا نوايا.»',
    'pain.5a': 'سجل متصل لكل فرع: ما بيع وروجع وسُلّم وطُولب به. هذا ما ينظر إليه المشتري أو المستثمر، وهذا ما تُقدَّر به القيمة.',
    'nav.modules': 'الوحدات', 'nav.pricing': 'الأسعار', 'nav.contact': 'اتصل بنا', 'nav.signin': 'تسجيل الدخول',
    'cta.demo': 'اطلب عرضًا توضيحيًا', 'cta.start': 'ابدأ الآن',
    'hero.eyebrow': 'شبكات الامتياز وسلاسل المتاجر · أدوات التشغيل',
    'hero.title': 'قيمة شبكتك هي ما يمكنك تسليمه.',
    'hero.subtitle': 'المعرفة التي تعيش في رأسي شخصين لا تُستنسخ ولا تُفوَّض ولا تُباع. ثماني وحدات تضع تشغيلك كتابةً: ما على كل شخص فعله، وما تم فعله، وما نتج عنه.',
    'hero.proof1': 'ما أُنجز يمكن إثباته', 'hero.proof2': 'شاشات حقيقية، لا نماذج',
    'brands.title': 'شبكات تدير أعمالها بهذه الوحدات',
    'modules.eyebrow': 'الوحدات', 'modules.title': 'وحدة لكل مهنة في الشبكة',
    'modules.lead': 'المقر يقرّر، والميدان ينفّذ، والأداة تحفظ الأثر. كل وحدة تنشر بطاقتها من مستودعها: ما تقرأه هنا يصف ما يعمل فعلًا، بالصور.',
    'modules.link': 'استكشف الوحدة', 'modules.new': 'جديد',
    'modules.all': 'كل الوحدات', 'modules.count': 'وحدات', 'modules.newcount': 'جديدة',
    'detail.back': 'كل الوحدات', 'detail.connected': 'وحدات مترابطة', 'detail.gain': 'النتيجة المقاسة',
    'detail.setup': 'التشغيل', 'detail.week': 'أسبوع', 'detail.sheet': 'احصل على الملف', 'detail.included': 'ما تفعله الوحدة',
    'detail.overview': 'الوحدة باختصار', 'detail.functions': 'الوظائف', 'detail.functions.title': 'كل وظيفة مع الشاشة التي تثبتها',
    'group.ventes': 'المبيعات', 'group.finance': 'المالية', 'group.marketing': 'التسويق',
    'group.logistique': 'اللوجستيات', 'group.assistance': 'الدعم', 'group.terrain': 'الميدان',
    'group.pilotage': 'القيادة',
    'shot.alt': 'لقطة شاشة للوحدة',
    'mock.brand': 'TFB Admin · بيانات تجريبية', 'mock.stores': '64 نقطة بيع',
    'mock.collected': 'المحصّل (يوليو)', 'mock.licenses': 'الاشتراكات', 'mock.vsjune': 'مقارنة بيونيو',
    'mock.thismonth': 'هذا الشهر', 'mock.active': 'نشط',
    'steps.eyebrow': 'بدء التشغيل', 'steps.title': 'أن تضع تشغيلك كتابةً، في أربع خطوات',
    'steps.1t': 'نكتب معيارك', 'steps.1d': 'علاماتك وفروعك وقواعدك: من يقرّر ماذا، ومن ينفّذ ماذا، ومن يرى ماذا.',
    'steps.2t': 'ننقل بياناتك', 'steps.2d': 'الكتالوج والأسعار وعملاء الشركات ومناطق التوصيل: تُستورد ولا تُعاد كتابتها.',
    'steps.3t': 'الميدان يعمل عليها', 'steps.3d': 'المطبخ والسائقون ومستشارو الشبكة: لكلٍّ شاشته، والجميع على البيانات نفسها.',
    'steps.4t': 'تقود بالوقائع', 'steps.4d': 'ما بيع وحُضّر وسُلّم وروجع وطُولب به. مجمّعًا لكل علامة، أولًا بأول.',
    'diff.eyebrow': 'ما الذي يتغيّر', 'diff.title': 'ما الذي يجعل الشبكة قابلة للاستنساخ',
    'diff.1t': 'المعيار يُطبَّق ولا يُستذكر', 'diff.1d': 'الكتالوج والأسعار والصيغ تُقرَّر في المقر وتُطبَّق في كل مكان. صاحب الامتياز يحتفظ بما هو محلي فقط.',
    'diff.2t': 'لا إعادة إدخال بين خطوتين', 'diff.2d': 'الكتالوج يغذّي المتجر، والمتجر يغذّي التحضير، والتحضير يغذّي الجولة. كل إعادة إدخال فرصة للانحراف.',
    'diff.3t': 'فرع إضافي يعني بطاقة إضافية', 'diff.3d': 'يرث الكتالوج والقواعد والصلاحيات. الافتتاح لم يعد يعني إعادة تركيب كل شيء.',
    'diff.4t': 'ثماني لغات، والعربية معكوسة', 'diff.4d': 'كل النصوص من قاعدة البيانات لا من الشيفرة. شبكة تعبر حدودًا لا تنتظر إصدارًا جديدًا.',
    'pricing.eyebrow': 'الأسعار', 'pricing.title': 'لكل نقطة بيع، لا لكل مستخدم',
    'pricing.lead': 'سعر لكل نقطة بيع لا لكل مستخدم: ادعُ أصحاب الامتياز والمديرين والفرق دون حساب المقاعد.',
    'pricing.recommended': 'موصى به', 'pricing.month': '/ شهريًا لكل نقطة بيع', 'pricing.custom': 'حسب الطلب',
    'plan.cta': 'اختر هذه الخطة', 'plan.cta.custom': 'تحدث إلى خبير',
    'contact.eyebrow': 'اتصل بنا', 'contact.title': 'لنتحدث عن شبكتك',
    'contact.lead': 'كم نقطة بيع، وكم علامة، وما الذي يفلت من يدك اليوم؟ نعود إليك خلال يوم عمل بعرض على حالاتك.',
    'contact.name': 'الاسم', 'contact.email': 'البريد المهني', 'contact.company': 'الشركة', 'contact.message': 'الرسالة',
    'contact.send': 'إرسال', 'contact.sent': 'تم استلام الرسالة', 'contact.sentmsg': 'نرد خلال يوم عمل واحد.',
    'contact.error': 'تعذّر الإرسال', 'contact.errormsg': 'تحقّق من بريدك وأعد المحاولة.',
    'contact.mail': 'contact@franchisebuddy.eu', 'contact.hours': 'الاثنين — الجمعة، 9 — 18 بتوقيت وسط أوروبا',
    'footer.product': 'المنتج', 'footer.company': 'الشركة', 'footer.legal': 'إشعارات قانونية', 'footer.langs': 'اللغات',
    'footer.tagline': 'نظام ERP سحابي لشبكات الامتياز وسلاسل المتاجر في المطاعم والتجزئة.',
    'footer.company.items': 'من نحن|الأمان|اتصل بنا',
    'footer.legal.items': 'إشعارات قانونية|الشروط|المعالجون الفرعيون',
    'footer.rights': '© 2026 The Franchise Buddy — ERP SaaS',
    'demo.title': 'اطلب عرضًا توضيحيًا', 'demo.desc': 'ثلاثون دقيقة، بياناتك، بلا عروض تقديمية.',
    'demo.locations': 'نقاط البيع', 'demo.cancel': 'إلغاء', 'demo.confirm': 'اطلب موعدًا',
    'checkout.title': 'إعادة التوجيه إلى Stripe', 'checkout.stub': 'لم يُضبط Stripe بعد — لم تُنشأ أي جلسة.',
    'brand.name': 'The Franchise Buddy',
  },
};

/**
 * tfb_translations, entity_type='module' — vide pour la même raison que MODULES.
 * La copie de chaque module (nom, description, explication, fonctions) vit dans
 * le `.tfb/module.json` de son dépôt, en français et en anglais, et arrive par
 * la sync. Le type reste exporté : le back-office et la sync s'y appuient.
 */
type ModuleCopy = { name: string; description: string; bullets?: string[]; metric?: [string, string] };

export const MODULE_STRINGS: Record<AuthoredLocale, Record<string, ModuleCopy>> = {
  fr: {}, en: {}, ar: {},
};

/**
 * tfb_translations, entity_type='plan'. Champs : name, description, features.
 *
 * `features` est vide partout : les listes de la maquette annonçaient un
 * découpage par palier (« Shop et facturation » en Starter, « tous les modules »
 * en Pro) qui n'a jamais été arrêté commercialement, et citait des modules qui
 * n'existent pas. Un palier annonce donc pour qui il est, et rien de plus, tant
 * que l'offre n'est pas fixée. Renseignez-les dans le back-office (Tarifs).
 */
type PlanCopy = { name: string; description: string; features: string[] };

export const PLAN_STRINGS: Record<AuthoredLocale, Record<string, PlanCopy>> = {
  fr: {
    starter: { name: 'Starter', description: 'Pour un réseau de moins de 10 points de vente.', features: [] },
    pro: { name: 'Pro', description: 'Pour les réseaux multi-enseignes en croissance.', features: [] },
    enterprise: { name: 'Enterprise', description: 'Pour les master-franchisés et les réseaux 500+.', features: [] },
  },
  en: {
    starter: { name: 'Starter', description: 'For networks under 10 stores.', features: [] },
    pro: { name: 'Pro', description: 'For growing multi-brand networks.', features: [] },
    enterprise: { name: 'Enterprise', description: 'For master franchisees and 500+ store networks.', features: [] },
  },
  ar: {
    starter: { name: 'Starter', description: 'لشبكة أقل من عشر نقاط بيع.', features: [] },
    pro: { name: 'Pro', description: 'للشبكات متعددة العلامات في مرحلة النمو.', features: [] },
    enterprise: { name: 'Enterprise', description: 'للامتياز الرئيسي والشبكات الكبيرة.', features: [] },
  },
};

/**
 * Boîte de réception de démonstration, pour que l'écran « Messages » du
 * back-office ne soit pas vide à la première ouverture. Ce sont des exemples
 * assumés : ni ces personnes ni ces sociétés n'existent, et les adresses sont en
 * `example.com`, réservé à cet usage. Supprimez-les dès les premiers vrais
 * messages.
 */
export const CONTACT_MESSAGES = [
  { name: 'Exemple — direction réseau', email: 'demo-1@example.com', company: 'Enseigne de démonstration', languageCode: 'fr', status: 'new', message: 'Message d’exemple : nous ouvrons douze points de vente l’an prochain, peut-on voir la console marque et les tournées de livraison en démo ?' },
  { name: 'Exemple — franchisé', email: 'demo-2@example.com', company: 'Point de vente de démonstration', languageCode: 'fr', status: 'new', message: 'Message d’exemple : est-ce que la console franchisé gère les clients professionnels et les créneaux de livraison ?' },
  { name: 'Exemple — message lu', email: 'demo-3@example.com', company: 'Enseigne de démonstration', languageCode: 'en', status: 'read', message: 'Sample message: can the kitchen app and the signage run in a store with an unreliable connection?' },
];
