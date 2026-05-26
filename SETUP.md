# 🎯 PubQuiz Dashboard - Snelle Startgids

## Stap 1: Database Importeren

1. Open phpMyAdmin (meestal op `http://localhost/phpmyadmin`)
2. Ga naar "Importeren"
3. Selecteer het bestand: `pubquiz.sql`
4. Klik op "OK"

**Alternatief (via terminal):**
```bash
mysql -u root -p < pubquiz.sql
```

## Stap 2: Database Connectie Aanpassen

Open het bestand: `database_connect.php`

Pas deze waarden aan naar jouw instellingen:
```php
define('DB_HOST', 'localhost');      // meestal 'localhost'
define('DB_USER', 'root');           // jouw MySQL gebruikersnaam
define('DB_PASSWORD', '');           // jouw MySQL wachtwoord
define('DB_NAME', 'pubquiz');        // database naam
```

**Tip:** Je vind je database instellingen meestal in je hostedconfig of phpMyAdmin.

## Stap 3: Plaats Bestanden Online

Upload alle bestanden naar je webserver:
- Via FTP naar je `public_html` of `htdocs` folder
- Zorg dat de mapstructuur intact blijft

## Stap 4: Eerste Keer Openen

Open in je browser:
```
https://jouwdomein.nl/pubquiz-dashboard/
of
http://localhost/pubquiz-dashboard/
```

## ✅ Checklist

- [ ] Database geïmporteerd (pubquiz.sql)
- [ ] database_connect.php aangepast
- [ ] Bestanden geupload naar webserver
- [ ] Database connectie test (open index.php)
- [ ] Minstens 1 team toegevoegd
- [ ] Vragen toegevoegd voor week 1

## 🚀 Eerste Gebruik

1. **Admin Access**
   - Ga naar: `/admin/` (e.g., http://localhost/pubquiz-dashboard/admin/)
   - Inlog: `admin` / `pubquiz2026`
   - Voeg teams en vragen toe

2. **Teams toevoegen**
   - In Admin panel: "Teams"
   - Voer teamnamen in
   - Klik "Team Toevoegen"

3. **Vragen toevoegen**
   - In Admin panel: "Vragen"
   - Voeg 25 vragen toe voor week 1
   - Gebruik bulk import voor sneller toevoegen

4. **Quiz starten**
   - Home pagina: Bekijk het podium met top 3 teams
   - Quiz pagina: `/quiz/` en klik "START"
   - Presenteer vragen met vorige/volgende knoppen
   - Toggle naar antwoorden
   - Update scores in Admin panel

## 📞 Hulp Nodig?

### Fout: "Connection failed"
→ Check je database instellingen in database_connect.php

### Fout: "Table 'pubquiz.teams' doesn't exist"
→ Zorg dat je pubquiz.sql correct hebt geïmporteerd

### Geen vragen zichtbaar
→ Zorg dat je vragen hebt toegevoegd voor de huidige week

### SQL Import werkt niet
→ Try handmatig via phpMyAdmin of zorg dat MySQL server draait

---

**Klaar?** Ga naar deze URLs:
- **Home & Leaderboard**: http://localhost/pubquiz-dashboard/
- **Quiz**: http://localhost/pubquiz-dashboard/quiz/
- **Admin**: http://localhost/pubquiz-dashboard/admin/

En start je quiz! 🎉
