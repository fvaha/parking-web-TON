export type Language = 'en' | 'sr' | 'de' | 'fr' | 'ar';

export interface Translations {
  [key: string]: {
    [lang in Language]?: string;
  };
}

export const translations: Translations = {
  // Header & Navigation
  'smart_parking': {
    en: 'Parkiraj.info',
    sr: 'Pametno Parkiranje',
    de: 'Intelligentes Parken',
    fr: 'Stationnement Intelligent'
  },
  'change': {
    en: 'Change',
    sr: 'Promeni',
    de: 'Ändern',
    fr: 'Changer'
  },
  'license_plate': {
    en: 'License Plate',
    sr: 'Registarska Tablica',
    de: 'Nummernschild',
    fr: 'Plaque d\'Immatriculation'
  },
  'map_view': {
    en: 'Map View',
    sr: 'Pregled Mape',
    de: 'Kartenansicht',
    fr: 'Vue Carte'
  },
  'spaces': {
    en: 'Spaces',
    sr: 'Mesta',
    de: 'Plätze',
    fr: 'Places'
  },
  'admin': {
    en: 'Admin',
    sr: 'Admin',
    de: 'Admin',
    fr: 'Admin'
  },

  // License Plate Input
  'welcome_to_smart_parking': {
    en: 'Welcome to Parkiraj.info',
    sr: 'Dobrodošli u Pametno Parkiranje',
    de: 'Willkommen beim Intelligenten Parken',
    fr: 'Bienvenue au Stationnement Intelligent'
  },
  'enter_license_plate_to_continue': {
    en: 'Enter your license plate to continue',
    sr: 'Unesite registarsku tablicu da nastavite',
    de: 'Geben Sie Ihr Kennzeichen ein, um fortzufahren',
    fr: 'Entrez votre plaque d\'immatriculation pour continuer'
  },
  'enter_license_plate_placeholder': {
    en: 'Enter license plate (e.g., ABC-123)',
    sr: 'Unesite registarsku tablicu (npr. ABC-123)',
    de: 'Kennzeichen eingeben (z.B. ABC-123)',
    fr: 'Entrez la plaque (ex: ABC-123)'
  },
  'continue': {
    en: 'Continue',
    sr: 'Nastavi',
    de: 'Weiter',
    fr: 'Continuer'
  },
  'setting': {
    en: 'Setting',
    sr: 'Postavljanje',
    de: 'Einstellen',
    fr: 'Configuration'
  },
  'change_plate': {
    en: 'Change Plate',
    sr: 'Promeni Tablicu',
    de: 'Kennzeichen ändern',
    fr: 'Changer la Plaque'
  },
  'license_plate_saved_info': {
    en: 'Your license plate will be saved on this device',
    sr: 'Vaša registarska tablica će biti sačuvana na ovom uređaju',
    de: 'Ihr Kennzeichen wird auf diesem Gerät gespeichert',
    fr: 'Votre plaque sera sauvegardée sur cet appareil'
  },
  'can_change_anytime_info': {
    en: 'You can change it anytime from the menu',
    sr: 'Možete je promeniti bilo kada iz menija',
    de: 'Sie können es jederzeit über das Menü ändern',
    fr: 'Vous pouvez la changer à tout moment depuis le menu'
  },

  // Validation Messages
  'license_plate_min_length': {
    en: 'License plate must be at least 2 characters',
    sr: 'Registarska tablica mora imati najmanje 2 karaktera',
    de: 'Kennzeichen muss mindestens 2 Zeichen haben',
    fr: 'La plaque doit contenir au moins 2 caractères'
  },
  'license_plate_max_length': {
    en: 'License plate cannot exceed 10 characters',
    sr: 'Registarska tablica ne može imati više od 10 karaktera',
    de: 'Kennzeichen darf nicht mehr als 10 Zeichen haben',
    fr: 'La plaque ne peut pas dépasser 10 caractères'
  },
  'license_plate_invalid_chars': {
    en: 'License plate can only contain letters, numbers, hyphens, and spaces',
    sr: 'Registarska tablica može sadržati samo slova, brojeve, crtice i razmake',
    de: 'Kennzeichen darf nur Buchstaben, Zahlen, Bindestriche und Leerzeichen enthalten',
    fr: 'La plaque ne peut contenir que des lettres, chiffres, tirets et espaces'
  },

  // Session Status
  'active_session': {
    en: 'Active Session',
    sr: 'Aktivna Sesija',
    de: 'Aktive Sitzung',
    fr: 'Session Active'
  },
  'space': {
    en: 'Space',
    sr: 'Mesto',
    de: 'Platz',
    fr: 'Place'
  },
  'started': {
    en: 'Started',
    sr: 'Započeto',
    de: 'Gestartet',
    fr: 'Commencé'
  },
  'cannot_reserve_other_spaces': {
    en: 'You cannot reserve other spaces while this session is active',
    sr: 'Ne možete rezervisati druga mesta dok je ova sesija aktivna',
    de: 'Sie können keine anderen Plätze reservieren, während diese Sitzung aktiv ist',
    fr: 'Vous ne pouvez pas réserver d\'autres places pendant cette session'
  },
  'park_car': {
    en: 'Park Car',
    sr: 'Parkiraj Auto',
    de: 'Auto parken',
    fr: 'Garer la Voiture'
  },
  'cancel_session': {
    en: 'Cancel Session',
    sr: 'Otkaži Sesiju',
    de: 'Sitzung abbrechen',
    fr: 'Annuler la Session'
  },
  'complete_session': {
    en: 'Complete Session',
    sr: 'Završi Sesiju',
    de: 'Sitzung beenden',
    fr: 'Terminer la Session'
  },

  // Parking Space Status
  'available': {
    en: 'Available',
    sr: 'Dostupno',
    de: 'Verfügbar',
    fr: 'Disponible'
  },
  'occupied': {
    en: 'Occupied',
    sr: 'Zauzeto',
    de: 'Besetzt',
    fr: 'Occupé'
  },
  'reserved': {
    en: 'Reserved',
    sr: 'Rezervisano',
    de: 'Reserviert',
    fr: 'Réservé'
  },
  'unknown': {
    en: 'Unknown',
    sr: 'Nepoznato',
    de: 'Unbekannt',
    fr: 'Inconnu'
  },

  // Parking Space Card
  'sensor': {
    en: 'Sensor',
    sr: 'Senzor',
    de: 'Sensor',
    fr: 'Capteur'
  },
  'coordinates': {
    en: 'Coordinates',
    sr: 'Koordinate',
    de: 'Koordinaten',
    fr: 'Coordonnées'
  },
  'plate': {
    en: 'Plate',
    sr: 'Tablica',
    de: 'Kennzeichen',
    fr: 'Plaque'
  },
  'since': {
    en: 'Since',
    sr: 'Od',
    de: 'Seit',
    fr: 'Depuis'
  },
  'session_active': {
    en: 'Session Active',
    sr: 'Sesija Aktivna',
    de: 'Sitzung Aktiv',
    fr: 'Session Active'
  },
  'reserve_space': {
    en: 'Reserve Space',
    sr: 'Rezerviši Mesto',
    de: 'Platz reservieren',
    fr: 'Réserver la Place'
  },
  'navigate': {
    en: 'Navigate',
    sr: 'Navigiraj',
    de: 'Navigieren',
    fr: 'Naviguer'
  },
  'session_warning_message': {
    en: 'Complete your current session first',
    sr: 'Prvo završite trenutnu sesiju',
    de: 'Beenden Sie zuerst Ihre aktuelle Sitzung',
    fr: 'Terminez d\'abord votre session actuelle'
  },

  // Street Search
  'search_streets_placeholder': {
    en: 'Search streets...',
    sr: 'Pretraži ulice...',
    de: 'Straßen suchen...',
    fr: 'Rechercher des rues...'
  },
  'showing_all_spaces': {
    en: 'Showing all {count} parking spaces',
    sr: 'Prikazujem sva {count} parking mesta',
    de: 'Zeige alle {count} Parkplätze',
    fr: 'Affichage de tous les {count} places de stationnement'
  },
  'found_spaces_in_street': {
    en: 'Found {count} parking spaces in "{street}"',
    sr: 'Pronađeno {count} parking mesta u "{street}"',
    de: '{count} Parkplätze in "{street}" gefunden',
    fr: 'Trouvé {count} places de stationnement dans "{street}"'
  },
  'available_streets': {
    en: 'Available streets:',
    sr: 'Dostupne ulice:',
    de: 'Verfügbare Straßen:',
    fr: 'Rues disponibles :'
  },
  'show_parking_spaces_in_street': {
    en: 'Show parking spaces in {street}',
    sr: 'Prikaži parking mesta u {street}',
    de: 'Parkplätze in {street} anzeigen',
    fr: 'Afficher les places de stationnement dans {street}'
  },

  // Reservation Modal
  'reserve_parking_space': {
    en: 'Reserve Parking Space',
    sr: 'Rezerviši Parking Mesto',
    de: 'Parkplatz reservieren',
    fr: 'Réserver une Place de Stationnement'
  },
  'space_id': {
    en: 'Space ID:',
    sr: 'ID Mesta:',
    de: 'Platz-ID:',
    fr: 'ID de Place :'
  },
  'confirm_reservation': {
    en: 'Confirm Reservation',
    sr: 'Potvrdi Rezervaciju',
    de: 'Reservierung bestätigen',
    fr: 'Confirmer la Réservation'
  },
  'cancel': {
    en: 'Cancel',
    sr: 'Otkaži',
    de: 'Abbrechen',
    fr: 'Annuler'
  },

  // Admin Login
  'admin_login': {
    en: 'Admin Login',
    sr: 'Admin Prijava',
    de: 'Admin-Anmeldung',
    fr: 'Connexion Admin'
  },
  'enter_credentials_to_access_admin': {
    en: 'Enter your credentials to access the admin panel',
    sr: 'Unesite vaše podatke za pristup admin panelu',
    de: 'Geben Sie Ihre Anmeldedaten ein, um auf das Admin-Panel zuzugreifen',
    fr: 'Entrez vos identifiants pour accéder au panneau d\'administration'
  },
  'username': {
    en: 'Username',
    sr: 'Korisničko Ime',
    de: 'Benutzername',
    fr: 'Nom d\'utilisateur'
  },
  'password': {
    en: 'Password',
    sr: 'Lozinka',
    de: 'Passwort',
    fr: 'Mot de passe'
  },
  'enter_username': {
    en: 'Enter username',
    sr: 'Unesite korisničko ime',
    de: 'Benutzername eingeben',
    fr: 'Entrez le nom d\'utilisateur'
  },
  'enter_password': {
    en: 'Enter password',
    sr: 'Unesite lozinku',
    de: 'Passwort eingeben',
    fr: 'Entrez le mot de passe'
  },
  'show': {
    en: 'Show',
    sr: 'Prikaži',
    de: 'Anzeigen',
    fr: 'Afficher'
  },
  'hide': {
    en: 'Hide',
    sr: 'Sakrij',
    de: 'Ausblenden',
    fr: 'Masquer'
  },
  'signing_in': {
    en: 'Signing in...',
    sr: 'Prijavljivanje...',
    de: 'Anmelden...',
    fr: 'Connexion...'
  },
  'sign_in': {
    en: 'Sign In',
    sr: 'Prijavi se',
    de: 'Anmelden',
    fr: 'Se connecter'
  },
  'default_credentials': {
    en: 'Default Credentials:',
    sr: 'Podrazumevani Podaci:',
    de: 'Standard-Anmeldedaten:',
    fr: 'Identifiants par défaut :'
  },
  'superadmin': {
    en: 'Superadmin:',
    sr: 'Superadmin:',
    de: 'Superadmin:',
    fr: 'Superadmin :'
  },

  // Admin Dashboard
  'admin_dashboard': {
    en: 'Admin Dashboard',
    sr: 'Admin Kontrolna Tabla',
    de: 'Admin-Dashboard',
    fr: 'Tableau de Bord Admin'
  },
  'welcome_admin': {
    en: 'Welcome, {username} ({role})',
    sr: 'Dobrodošli, {username} ({role})',
    de: 'Willkommen, {username} ({role})',
    fr: 'Bienvenue, {username} ({role})'
  },
  'refresh': {
    en: 'Refresh',
    sr: 'Osveži',
    de: 'Aktualisieren',
    fr: 'Actualiser'
  },
  'export': {
    en: 'Export',
    sr: 'Izvezi',
    de: 'Exportieren',
    fr: 'Exporter'
  },
  'logout': {
    en: 'Logout',
    sr: 'Odjavi se',
    de: 'Abmelden',
    fr: 'Déconnexion'
  },

  // Admin Tabs
  'overview': {
    en: 'Overview',
    sr: 'Pregled',
    de: 'Übersicht',
    fr: 'Vue d\'ensemble'
  },
  'sensors': {
    en: 'Sensors',
    sr: 'Senzori',
    de: 'Sensoren',
    fr: 'Capteurs'
  },
  'analytics': {
    en: 'Analytics',
    sr: 'Analitika',
    de: 'Analysen',
    fr: 'Analyses'
  },
  'bookings': {
    en: 'Bookings',
    sr: 'Rezervacije',
    de: 'Buchungen',
    fr: 'Réservations'
  },
  'active_sessions': {
    en: 'Active Sessions',
    sr: 'Aktivne Sesije',
    de: 'Aktive Sitzungen',
    fr: 'Sessions Actives'
  },
  'admin_users': {
    en: 'Admin Users',
    sr: 'Admin Korisnici',
    de: 'Admin-Benutzer',
    fr: 'Utilisateurs Admin'
  },
  'activity_logs': {
    en: 'Activity Logs',
    sr: 'Logovi Aktivnosti',
    de: 'Aktivitätsprotokolle',
    fr: 'Journaux d\'activité'
  },
  'zone_management': {
    en: 'Zone Management',
    sr: 'Upravljanje Zonama',
    de: 'Zonenverwaltung',
    fr: 'Gestion des Zones'
  },
  'admin_management': {
    en: 'Admin Management',
    sr: 'Admin Upravljanje',
    de: 'Admin-Verwaltung',
    fr: 'Gestion Admin'
  },

  // Data Summary
  'data_summary': {
    en: 'Data Summary',
    sr: 'Pregled Podataka',
    de: 'Datenzusammenfassung',
    fr: 'Résumé des Données'
  },
  'usage_records': {
    en: 'Usage Records:',
    sr: 'Zapisi Korišćenja:',
    de: 'Nutzungsaufzeichnungen:',
    fr: 'Enregistrements d\'utilisation :'
  },
  'active_reservations': {
    en: 'Active Reservations:',
    sr: 'Aktivne Rezervacije:',
    de: 'Aktive Reservierungen:',
    fr: 'Réservations actives :'
  },
  'completed_reservations': {
    en: 'Completed Reservations:',
    sr: 'Završene Rezervacije:',
    de: 'Abgeschlossene Reservierungen:',
    fr: 'Réservations terminées :'
  },

  // Statistics
  'total_spaces': {
    en: 'Total Spaces',
    sr: 'Ukupno Mesta',
    de: 'Gesamtplätze',
    fr: 'Places Totales'
  },
  'utilization_rate': {
    en: 'Utilization Rate',
    sr: 'Stopa Iskorišćenja',
    de: 'Auslastungsrate',
    fr: 'Taux d\'utilisation'
  },
  'total_revenue': {
    en: 'Total Revenue',
    sr: 'Ukupan Prihod',
    de: 'Gesamteinnahmen',
    fr: 'Revenus Totaux'
  },
  'average_duration': {
    en: 'Average Duration',
    sr: 'Prosečno Trajanje',
    de: 'Durchschnittsdauer',
    fr: 'Durée Moyenne'
  },
  'daily_usage': {
    en: 'Daily Usage',
    sr: 'Dnevna Upotreba',
    de: 'Tägliche Nutzung',
    fr: 'Utilisation Quotidienne'
  },
  'hourly_usage': {
    en: 'Hourly Usage',
    sr: 'Časovna Upotreba',
    de: 'Stündliche Nutzung',
    fr: 'Utilisation Horaire'
  },

  // Sensors Management
  'sensors_management': {
    en: 'Sensors Management',
    sr: 'Upravljanje Senzorima',
    de: 'Sensorverwaltung',
    fr: 'Gestion des Capteurs'
  },
  'add_sensor': {
    en: '+ Add Sensor',
    sr: '+ Dodaj Senzor',
    de: '+ Sensor hinzufügen',
    fr: '+ Ajouter un Capteur'
  },
  'edit': {
    en: 'Edit',
    sr: 'Uredi',
    de: 'Bearbeiten',
    fr: 'Modifier'
  },
  'delete': {
    en: 'Delete',
    sr: 'Obriši',
    de: 'Löschen',
    fr: 'Supprimer'
  },
  'edit_sensor': {
    en: 'Edit Sensor',
    sr: 'Uredi Senzor',
    de: 'Sensor bearbeiten',
    fr: 'Modifier le Capteur'
  },
  'add_new_sensor': {
    en: 'Add New Sensor',
    sr: 'Dodaj Novi Senzor',
    de: 'Neuen Sensor hinzufügen',
    fr: 'Ajouter un Nouveau Capteur'
  },
  'sensor_name': {
    en: 'Sensor Name',
    sr: 'Ime Senzora',
    de: 'Sensor-Name',
    fr: 'Nom du Capteur'
  },
  'street_name': {
    en: 'Street Name',
    sr: 'Ime Ulice',
    de: 'Straßenname',
    fr: 'Nom de la Rue'
  },
  'parking_zone': {
    en: 'Parking Zone',
    sr: 'Parking Zona',
    de: 'Parkzone',
    fr: 'Zone de Stationnement'
  },
  'select_zone_optional': {
    en: 'Select a zone (optional)',
    sr: 'Izaberite zonu (opciono)',
    de: 'Zone auswählen (optional)',
    fr: 'Sélectionner une zone (optionnel)'
  },
  'zone_pricing_info': {
    en: 'Choose a parking zone to set pricing for this sensor\'s parking space',
    sr: 'Izaberite parking zonu za postavljanje cene za parking mesto ovog senzora',
    de: 'Wählen Sie eine Parkzone aus, um die Preise für den Parkplatz dieses Sensors festzulegen',
    fr: 'Choisissez une zone de stationnement pour définir les tarifs de la place de ce capteur'
  },
  'latitude': {
    en: 'Latitude',
    sr: 'Geografska Širina',
    de: 'Breitengrad',
    fr: 'Latitude'
  },
  'longitude': {
    en: 'Longitude',
    sr: 'Geografska Dužina',
    de: 'Längengrad',
    fr: 'Longitude'
  },
  'paste_coordinates_from_clipboard': {
    en: '📋 Paste Coordinates from Clipboard',
    sr: '📋 Nalepi Koordinate iz Clipboard-a',
    de: '📋 Koordinaten aus Zwischenablage einfügen',
    fr: '📋 Coller les coordonnées depuis le presse-papiers'
  },
  'coordinate_parsing_error': {
    en: 'Could not parse coordinates from clipboard. Please copy coordinates in format like "43.140000, 20.517500" or "43.140000,20.517500"',
    sr: 'Nije moguće parsirati koordinate iz clipboard-a. Molimo kopirajte koordinate u formatu kao "43.140000, 20.517500" ili "43.140000,20.517500"',
    de: 'Koordinaten konnten nicht aus der Zwischenablage geparst werden. Bitte kopieren Sie Koordinaten im Format wie "43.140000, 20.517500" oder "43.140000,20.517500"',
    fr: 'Impossible de parser les coordonnées depuis le presse-papiers. Veuillez copier les coordonnées au format "43.140000, 20.517500" ou "43.140000,20.517500"'
  },
  'clipboard_read_error': {
    en: 'Could not read from clipboard. Please paste coordinates manually.',
    sr: 'Nije moguće čitati iz clipboard-a. Molimo nalepite koordinate ručno.',
    de: 'Konnte nicht aus der Zwischenablage lesen. Bitte fügen Sie Koordinaten manuell ein.',
    fr: 'Impossible de lire depuis le presse-papiers. Veuillez coller les coordonnées manuellement.'
  },
  'coordinate_info': {
    en: 'Set the exact location where the sensor is installed. You can paste coordinates directly from Google Maps or other sources, or use the "Paste Coordinates" button above. This will determine the parking space rectangle position on the map.',
    sr: 'Postavite tačnu lokaciju gde je senzor instaliran. Možete nalepiti koordinate direktno iz Google Maps-a ili drugih izvora, ili koristiti dugme "Nalepi Koordinate" iznad. Ovo će odrediti poziciju parking mesta na mapi.',
    de: 'Legen Sie den genauen Standort fest, an dem der Sensor installiert ist. Sie können Koordinaten direkt aus Google Maps oder anderen Quellen einfügen oder die Schaltfläche "Koordinaten aus Zwischenablage einfügen" oben verwenden. Dies bestimmt die Position des Parkplatzes auf der Karte.',
    fr: 'Définissez l\'emplacement exact où le capteur est installé. Vous pouvez coller les coordonnées directement depuis Google Maps ou d\'autres sources, ou utiliser le bouton "Coller les coordonnées" ci-dessus. Cela déterminera la position du rectangle de stationnement sur la carte.'
  },

  // Admin User Management
  'admin_user_management': {
    en: 'Admin User Management',
    sr: 'Upravljanje Admin Korisnicima',
    de: 'Admin-Benutzerverwaltung',
    fr: 'Gestion des Utilisateurs Admin'
  },
  'add_admin_user': {
    en: 'Add Admin User',
    sr: 'Dodaj Admin Korisnika',
    de: 'Admin-Benutzer hinzufügen',
    fr: 'Ajouter un Utilisateur Admin'
  },
  'edit_admin_user': {
    en: 'Edit Admin User',
    sr: 'Uredi Admin Korisnika',
    de: 'Admin-Benutzer bearbeiten',
    fr: 'Modifier l\'Utilisateur Admin'
  },
  'add_new_admin_user': {
    en: 'Add New Admin User',
    sr: 'Dodaj Novog Admin Korisnika',
    de: 'Neuen Admin-Benutzer hinzufügen',
    fr: 'Ajouter un Nouvel Utilisateur Admin'
  },
  'email': {
    en: 'Email',
    sr: 'Email',
    de: 'E-Mail',
    fr: 'Email'
  },
  'role': {
    en: 'Role',
    sr: 'Uloga',
    de: 'Rolle',
    fr: 'Rôle'
  },
  'password_required_new': {
    en: 'Password is required for new users',
    sr: 'Lozinka je obavezna za nove korisnike',
    de: 'Passwort ist für neue Benutzer erforderlich',
    fr: 'Le mot de passe est requis pour les nouveaux utilisateurs'
  },
  'password_leave_blank': {
    en: 'leave blank to keep current',
    sr: 'ostavite prazno da zadržite trenutnu',
    de: 'leer lassen, um aktuelles beizubehalten',
    fr: 'laisser vide pour conserver l\'actuel'
  },
  'update_user': {
    en: 'Update User',
    sr: 'Ažuriraj Korisnika',
    de: 'Benutzer aktualisieren',
    fr: 'Mettre à Jour l\'Utilisateur'
  },
  'add_user': {
    en: 'Add User',
    sr: 'Dodaj Korisnika',
    de: 'Benutzer hinzufügen',
    fr: 'Ajouter l\'Utilisateur'
  },

  // Admin Logs
  'admin_activity_logs': {
    en: 'Admin Activity Logs',
    sr: 'Logovi Admin Aktivnosti',
    de: 'Admin-Aktivitätsprotokolle',
    fr: 'Journaux d\'activité Admin'
  },
  'track_admin_actions': {
    en: 'Track all admin actions and system changes',
    sr: 'Pratite sve admin akcije i sistemske promene',
    de: 'Verfolgen Sie alle Admin-Aktionen und Systemänderungen',
    fr: 'Suivre toutes les actions admin et changements système'
  },
  'previous_values': {
    en: 'Previous Values:',
    sr: 'Prethodne Vrednosti:',
    de: 'Vorherige Werte:',
    fr: 'Valeurs précédentes :'
  },
  'new_values': {
    en: 'New Values:',
    sr: 'Nove Vrednosti:',
    de: 'Neue Werte:',
    fr: 'Nouvelles valeurs :'
  },

  // Common Actions
  'save': {
    en: 'Save',
    sr: 'Sačuvaj',
    de: 'Speichern',
    fr: 'Sauvegarder'
  },
  'update': {
    en: 'Update',
    sr: 'Ažuriraj',
    de: 'Aktualisieren',
    fr: 'Mettre à jour'
  },
  'close': {
    en: 'Close',
    sr: 'Zatvori',
    de: 'Schließen',
    fr: 'Fermer'
  },
  'confirm_delete_sensor': {
    en: 'Are you sure you want to delete this sensor?',
    sr: 'Da li ste sigurni da želite da obrišete ovaj senzor?',
    de: 'Sind Sie sicher, dass Sie diesen Sensor löschen möchten?',
    fr: 'Êtes-vous sûr de vouloir supprimer ce capteur ?'
  },
  'confirm_delete_admin_user': {
    en: 'Are you sure you want to delete this admin user?',
    sr: 'Da li ste sigurni da želite da obrišete ovog admin korisnika?',
    de: 'Sind Sie sicher, dass Sie diesen Admin-Benutzer löschen möchten?',
    fr: 'Êtes-vous sûr de vouloir supprimer cet utilisateur admin ?'
  },
  'reservations': {
    en: 'Reservations',
    sr: 'Rezervacije',
    de: 'Reservierungen',
    fr: 'Réservations'
  },

  // Error Messages
  'please_fill_required_fields': {
    en: 'Please fill in all required fields',
    sr: 'Molimo popunite sva obavezna polja',
    de: 'Bitte füllen Sie alle erforderlichen Felder aus',
    fr: 'Veuillez remplir tous les champs obligatoires'
  },
  'login_failed': {
    en: 'Login failed',
    sr: 'Prijava nije uspela',
    de: 'Anmeldung fehlgeschlagen',
    fr: 'Échec de la connexion'
  },
  'unexpected_error': {
    en: 'An unexpected error occurred',
    sr: 'Došlo je do neočekivane greške',
    de: 'Ein unerwarteter Fehler ist aufgetreten',
    fr: 'Une erreur inattendue s\'est produite'
  },
  'please_enter_both_username_password': {
    en: 'Please enter both username and password',
    sr: 'Molimo unesite i korisničko ime i lozinku',
    de: 'Bitte geben Sie sowohl Benutzername als auch Passwort ein',
    fr: 'Veuillez saisir le nom d\'utilisateur et le mot de passe'
  },

  // Weather & Air Quality
  'temperature': {
    en: 'Temperature',
    sr: 'Temperatura',
    de: 'Temperatur',
    fr: 'Température'
  },
  'air_quality': {
    en: 'Air Quality',
    sr: 'Kvalitet Vazduha',
    de: 'Luftqualität',
    fr: 'Qualité de l\'Air'
  },
  'humidity': {
    en: 'Humidity',
    sr: 'Vlažnost',
    de: 'Luftfeuchtigkeit',
    fr: 'Humidité'
  },
  'failed_to_fetch_weather': {
    en: 'Failed to fetch weather',
    sr: 'Neuspešno učitavanje vremena',
    de: 'Wetter konnte nicht abgerufen werden',
    fr: 'Échec de la récupération de la météo'
  },

  // Parking Status
  'vacant': {
    en: 'Vacant',
    sr: 'Slobodno',
    de: 'Frei',
    fr: 'Libre'
  },

  // Common
  'loading': {
    en: 'Loading...',
    sr: 'Učitavanje...',
    de: 'Laden...',
    fr: 'Chargement...'
  },
  'error': {
    en: 'Error',
    sr: 'Greška',
    de: 'Fehler',
    fr: 'Erreur'
  },

  // Map Controls
  'switch_to_google_maps': {
    en: 'Switch to Google Maps',
    sr: 'Prebaci na Google Maps',
    de: 'Zu Google Maps wechseln',
    fr: 'Passer à Google Maps'
  },
  'switch_to_osm': {
    en: 'Switch to OSM',
    sr: 'Prebaci na OSM',
    de: 'Zu OSM wechseln',
    fr: 'Passer à OSM'
  },
  'open_in_google_maps': {
    en: 'Open in Google Maps App',
    sr: 'Otvori u Google Maps aplikaciji',
    de: 'In Google Maps App öffnen',
    fr: 'Ouvrir dans l\'app Google Maps'
  },
  'hide_route': {
    en: '✕ Hide Route',
    sr: '✕ Sakrij Rutu',
    de: '✕ Route ausblenden',
    fr: '✕ Masquer l\'itinéraire'
  },

  // Parking Status Panel
  'parking_status': {
    en: 'Parking Status',
    sr: 'Status Parkiranja',
    de: 'Parkplatzstatus',
    fr: 'Statut du Stationnement'
  },
  'your_location': {
    en: 'Your Location',
    sr: 'Vaša Lokacija',
    de: 'Ihr Standort',
    fr: 'Votre Emplacement'
  },

  // Spaces View
  'parking_spaces': {
    en: 'Parking Spaces',
    sr: 'Parking Mesta',
    de: 'Parkplätze',
    fr: 'Places de Stationnement'
  },
  'status': {
    en: 'Status',
    sr: 'Status',
    de: 'Status',
    fr: 'Statut'
  },
  'sensor_id': {
    en: 'Sensor ID',
    sr: 'ID Senzora',
    de: 'Sensor-ID',
    fr: 'ID du Capteur'
  },

  // Admin Dashboard
  'dashboard': {
    en: 'Dashboard',
    sr: 'Kontrolna Tabla',
    de: 'Dashboard',
    fr: 'Tableau de Bord'
  },

  'export_data': {
    en: 'Export Data',
    sr: 'Izvezi Podatke',
    de: 'Daten exportieren',
    fr: 'Exporter les Données'
  },
  'total_sensors': {
    en: 'Total Sensors',
    sr: 'Ukupno Senzora',
    de: 'Gesamtsensoren',
    fr: 'Capteurs Totaux'
  },
  'active_sensors': {
    en: 'Active Sensors',
    sr: 'Aktivni Senzori',
    de: 'Aktive Sensoren',
    fr: 'Capteurs Actifs'
  },
  'parking_spaces_count': {
    en: 'Parking Spaces',
    sr: 'Parking Mesta',
    de: 'Parkplätze',
    fr: 'Places de Stationnement'
  },
  'occupied_spaces': {
    en: 'Occupied Spaces',
    sr: 'Zauzeta Mesta',
    de: 'Besetzte Plätze',
    fr: 'Places Occupées'
  }
};

export class LanguageService {
  private static instance: LanguageService;
  private current_language: Language = 'en';

  private constructor() {
    const saved_lang = localStorage.getItem('parking_language') as Language;
    if (saved_lang && ['en', 'sr', 'de', 'fr', 'ar'].includes(saved_lang)) {
      this.current_language = saved_lang;
    }
  }

  static getInstance(): LanguageService {
    if (!LanguageService.instance) {
      LanguageService.instance = new LanguageService();
    }
    return LanguageService.instance;
  }

  get_current_language(): Language {
    return this.current_language;
  }

  set_language(language: Language): void {
    this.current_language = language;
    localStorage.setItem('parking_language', language);
    window.location.reload(); // Reload to apply changes
  }

  get_available_languages(): { code: Language; name: string; flag: string }[] {
    return [
      { code: 'en', name: 'US English', flag: '🇺🇸' },
      { code: 'sr', name: 'RS Srpski', flag: '🇷🇸' },
      { code: 'de', name: 'DE Deutsch', flag: '🇩🇪' },
      { code: 'fr', name: 'FR Français', flag: '🇫🇷' },
      { code: 'ar', name: 'AR العربية', flag: '🇸🇦' }
    ];
  }

  translate(key: string): string {
    const translation = translations[key];
    if (!translation) {
      console.warn(`Translation missing for key: ${key}`);
      return key;
    }
    return translation[this.current_language] || translation['en'] || key;
  }

  t(key: string): string {
    return this.translate(key);
  }
}
