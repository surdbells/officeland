# Location photographs

Three files, referenced by `core/layout/locations-strip.ts`:

```
head-office.jpg          Century 21 Mall, 14B Idowu Martins Street
furniture-showroom.jpg   No 18, Idowu Martins Street
dubai-office.jpg         Platinum Tower, Jumeirah Lakes Towers
```

Drop them here, then set `photo` on the matching entry in
`locations-strip.ts` to `/images/locations/head-office.jpg` and so on. The
card shows the photograph instead of the icon as soon as the field is set.

**Not the media pipeline, deliberately.** These are three fixed brand assets
that never vary per environment, not catalogue content somebody uploads
through the admin, so content-addressed storage and derivative generation buy
nothing here. `img-src 'self'` is satisfied either way.

Landscape, 16:9, at least 1280px wide. The card renders them at a fixed aspect
ratio, so anything squarer will be cropped top and bottom.

Alt text lives beside the `photo` field in the same file, because it describes
what the picture shows and belongs with the picture rather than in a sidecar
nobody opens.
