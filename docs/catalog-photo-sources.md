# Demo catalog photographs

The 14 active demo category and product illustrations have been replaced with real photographs.
They keep the existing `public/images/all575/category-art/<slug>.png` paths because existing local
catalog records already point there. No seeding, database reset, stock update or product rename
is needed. Pull branch `v2` in **all-575-api**, keep the API running, and hard-refresh the app
(Ctrl+F5 in a desktop browser) to discard cached illustrations.

These are licensed sample photographs for the local/demo catalog, not verified photographs of
seller inventory. Replace them with the actual seller's product photos before launch. The
normal API image fields remain the source of truth; no photo substitution is done in the app.
The demo seeder remains blocked in production.

Files are 512 x 512 PNGs, cropped where needed and padded without stretching. They contain
photography, not generated imagery or illustrated icons. Original photo backgrounds are retained.
The JSON manifest records each source, photographer, crop and final file hash.

Sources: [Pexels License](https://www.pexels.com/license/) and
[Pixabay Content License](https://pixabay.com/service/license-summary/).
Use of a visible brand in a demo photo does not imply endorsement or availability for sale.

| File | Photographer | Source |
| --- | --- | --- |
| clothing.png | dayong tien | [Original photo](https://www.pexels.com/photo/a-basic-white-t-shirt-18186106/) |
| toys.png | Nothing Ahead | [Original photo](https://www.pexels.com/photo/brown-bear-plush-toy-on-white-surface-5786759/) |
| cookware.png | Silberfuchs | [Original photo](https://pixabay.com/photos/pot-cookware-kitchen-unclean-554068/) |
| cleaning.png | Markus Winkler | [Original photo](https://www.pexels.com/photo/stall-with-washing-powder-13768941/) |
| stationery.png | Jess Bailey Designs | [Original photo](https://www.pexels.com/photo/three-ball-point-pens-983826/) |
| accessories.png | Saliem Moeslan | [Original photo](https://www.pexels.com/photo/sunglasses-on-a-white-background-27111647/) |
| hand-tools.png | Roger Brown | [Original photo](https://www.pexels.com/photo/different-wrenches-on-a-brown-surface-5414384/) |
| shampoos.png | TRAFFIC On | [Original photo](https://www.pexels.com/photo/shampoo-in-design-bottle-14656326/) |
| other.png | Melike B | [Original photo](https://www.pexels.com/photo/empty-baskets-on-white-background-10079392/) |
| vegetables.png | Daka | [Original photo](https://www.pexels.com/photo/cherry-tomatoes-on-white-background-11229105/) |
| fruits.png | Evgeniy Alekseyev | [Original photo](https://www.pexels.com/photo/red-apple-on-white-surface-7333141/) |
| kitchen-utensils.png | Nelly Aran | [Original photo](https://www.pexels.com/photo/fork-on-spoon-20849333/) |
| shoes.png | Melvin Buezo | [Original photo](https://www.pexels.com/photo/photo-of-sneakers-on-white-background-2529148/) |
| towels.png | Atlantic Ambience | [Original photo](https://www.pexels.com/photo/clean-cotton-towels-12932367/) |
