# Miltydraft generator

[Visit the app here](https://milty.shenanigans.be/).

An expanded version of miltydraft.com, with saving/sharing drafts across sessions.

## Requirements: 
* make sure you have [docker](https://docs.docker.com/get-started/) installed

## Getting started

To install a local copy of this app you can clone it from the Git Repo: 

`git@github.com:shenanigans-be/miltydraft.git`

Then follow these steps:

1. Add `127.0.0.1 milty.localhost` to your `/etc/hosts` file. This first step is optional though. You can use
   127.0.0.1 directly as well.
2. Run `docker compose up -d --build`. This will first build the image, then start all services.
3. Run `docker compose exec app composer install`. This will install all php dependencies.
4. Create a `.env` file. See `.env.example` for details.
5. Go to [https://milty.localhost](https://milty.localhost) in your browser (or http://localhost if you don't want to go through the hassle of the following steps) 
6. Your browser might give you some scary warnings about untrusted certificates. That's because we're using a self-signed certificate.
If you want to, you can add the certificate to your device's truster certificate.
7. Run `docker compose exec app cat /home/app/.local/share/caddy/pki/authorities/local/root.crt > caddy-cert.crt` to make a copy of the certificate in `caddy-cert.crt` (which you can then import wherever you need it)

## Draft modes

The app offers two modes, chosen with the switch at the top of the settings form:

* **Milty draft** — the original: pre-built slices, factions and seating positions, drafted in
  snake order one pick at a time.
* **Franken draft** — the fan-made variant where factions are taken apart and reassembled. Every
  player gets a bag of components, everyone picks at the same time, and the bags pass around the
  table between rounds. See [docs/frankendraft-spec.md](docs/frankendraft-spec.md) for the rules
  and the design, and [docs/frankendraft-plan.md](docs/frankendraft-plan.md) for build status.

The two modes share storage, sessions/passkeys, tile data and the map renderer, but nothing else:
Franken lives in its own `App\Franken` namespace with its own templates and JS.

### Franken component data

The draftable components live in `data/franken/*.json` and are generated, not hand-maintained:

```
php bin/sync-franken-data.php              # re-download and rebuild
php bin/sync-franken-data.php --offline    # rebuild from the cached download
php bin/sync-franken-data.php --check      # fail if the committed data is stale
```

The source is the [AsyncTI4 bot's](https://github.com/AsyncTI4/TI4_map_generator_bot) component
database, which also carries the community's Franken errata (which components are undraftable,
which can be exchanged for which, and the Franken-specific card wordings). That project releases
its software and data into the public domain **but explicitly excludes art assets**, so the sync
imports rules text and identifiers only — Franken components are rendered as text, reusing only the
tile and faction images this repo already ships. Do not add card art to the generated files.

`data/FrankenDataTest.php` checks the generated data for consistency, so run the test suite after a
re-sync.

### Libraries and Dependencies

Frontend runs on vanilla JS/jQuery (I'm aware jQuery is a bit of a blast from the past at this point; sue me and/or change it and PR me if you want) and the Back-end is vanilla PHP.
As such there's no build-system, or compiling required except for the steps described above.

To make this app as lean and mean (and easy to understand for anyone) as possible, external dependencies, both in the front- and backend should be kept to an absolute minimum.

### Understanding the App flow

1. Players come in on index.php and choose their options. 
2. A JSON config file is created (either locally or remotely, depending on .env settings) with a unique ID
3. That Draft ID is also the Draft URL: APP_URL/d/{draft-id} (URL rewriting is done via Caddy locally)
4. Players (or the Admin) make draft choices, which updates the draft json file (with very loose security, since we're assuming a very low amount of bad actors)
