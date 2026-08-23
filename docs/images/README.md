# Images

Drop images here with a `.txt` sidecar each, then run `media:import`.

```
office-furniture-seating.jpg
office-furniture-seating.txt   <- "Mesh-back task chairs in the Victoria Island showroom"
```

```bash
docker compose exec api php artisan media:import --path=/docs/images                       # dry run
docker compose exec api php artisan media:import --path=/docs/images --attach-categories --apply
sh scripts/serve.sh --rebuild
```

**The sidecar is not optional.** §12.6 makes alt text mandatory at attach
time and `Media::assertAttachable()` enforces it. A file without one is
skipped and reported rather than given an invented description, because a
made-up alt is a lie to a screen reader and worse than no image at all.

**Filenames name the category.** A category path with its slashes replaced by
dashes: `/wpc-doors-interiors/wpc-doors/` becomes
`wpc-doors-interiors-wpc-doors.jpg`. A filename that names no category is
imported but not attached, rather than guessed at.

The images themselves are not committed — only this README. They are ingested
into the content-addressed store, which is where the application reads them
from.
