# Changelog

All notable changes to this project will be documented in this file. See
[Conventional Commits](https://conventionalcommits.org) for commit guidelines.

## [4.15.0](https://github.com/myparcelnl/magento/compare/v4.14.4...v4.15.0) (2024-06-10)


### :bug: Bug Fixes

* **checkout:** fix mailbox options ([#844](https://github.com/myparcelnl/magento/issues/844)) ([495ea80](https://github.com/myparcelnl/magento/commit/495ea806b057745435e2ab38566a7548fefbf7e7))


### :sparkles: New Features

* add mailbox to dpd ([#843](https://github.com/myparcelnl/magento/issues/843)) ([0fbd8ee](https://github.com/myparcelnl/magento/commit/0fbd8eef099f7f21b03d8bafe0c99a78680d801b))
* change large package name ([#845](https://github.com/myparcelnl/magento/issues/845)) ([50fc945](https://github.com/myparcelnl/magento/commit/50fc945806d85ccee1ee3038f577a3e3015ce026))

## [4.14.4](https://github.com/myparcelnl/magento/compare/v4.14.3...v4.14.4) (2024-05-13)


### :bug: Bug Fixes

* **checkout:** show nicer messages in delivery options ([#842](https://github.com/myparcelnl/magento/issues/842)) ([ae71d80](https://github.com/myparcelnl/magento/commit/ae71d80aa542ca1d700b01fd3fa7e91f013cdf06))
* **checkout:** stabilise choosing pickup point ([#841](https://github.com/myparcelnl/magento/issues/841)) ([aaa21d4](https://github.com/myparcelnl/magento/commit/aaa21d4c3bc12ceaad50ca6b5aef058ddf9bd006))
* **export:** allow signature or only_recipient for be ([#840](https://github.com/myparcelnl/magento/issues/840)) ([8c10cc3](https://github.com/myparcelnl/magento/commit/8c10cc3e886e231f2a661e189c697c5cf31a0b9b))

## [4.14.3](https://github.com/myparcelnl/magento/compare/v4.14.2...v4.14.3) (2024-04-22)


### :bug: Bug Fixes

* **checkout:** work with bbp and do6 from cdn ([#837](https://github.com/myparcelnl/magento/issues/837)) ([c560351](https://github.com/myparcelnl/magento/commit/c560351cd8e0810489f270dd527d56b0f8971abb))
* prevent camel cased options from breaking order detail page ([#838](https://github.com/myparcelnl/magento/issues/838)) ([bc8cff4](https://github.com/myparcelnl/magento/commit/bc8cff4ce27b48d6e7f0ee823a80b1c21a1c2ac6))

## [4.14.2](https://github.com/myparcelnl/magento/compare/v4.14.1...v4.14.2) (2024-04-04)


### :bug: Bug Fixes

* add delivery options 6 ([#835](https://github.com/myparcelnl/magento/issues/835)) ([a253bcc](https://github.com/myparcelnl/magento/commit/a253bccf75ae825eae19cee3e2b48eaedc8279c7))

## [4.14.1](https://github.com/myparcelnl/magento/compare/v4.14.0...v4.14.1) (2024-04-02)


### :bug: Bug Fixes

* **migration:** allow migration to run with invalid scopes present ([#834](https://github.com/myparcelnl/magento/issues/834)) ([6eb73f6](https://github.com/myparcelnl/magento/commit/6eb73f62d9adc31fb3da8891ca2a962122d47d68))

## [4.14.0](https://github.com/myparcelnl/magento/compare/v4.13.0...v4.14.0) (2024-03-29)


### :sparkles: New Features

* add international bbp ([#826](https://github.com/myparcelnl/magento/issues/826)) ([f9d0b63](https://github.com/myparcelnl/magento/commit/f9d0b63458cb14cc470cd371b552bd8ef9cea17f))
* add price surcharge option ([#828](https://github.com/myparcelnl/magento/issues/828)) ([b3cee24](https://github.com/myparcelnl/magento/commit/b3cee24472831ff5386ecd035051e39c638dffdd))
* include delivery options 6 ([#830](https://github.com/myparcelnl/magento/issues/830)) ([82a41e3](https://github.com/myparcelnl/magento/commit/82a41e38b549da28934bf675fc5a66b4a360f458))

## [4.13.0](https://github.com/myparcelnl/magento/compare/v4.12.1...v4.13.0) (2024-03-13)


### :sparkles: New Features

* activate dpd for myparcel contract ([#822](https://github.com/myparcelnl/magento/issues/822)) ([ee59c0d](https://github.com/myparcelnl/magento/commit/ee59c0d7959207dd53e21b7776f9c666e53fb80e))
* add package type package small ([#825](https://github.com/myparcelnl/magento/issues/825)) ([6d1c461](https://github.com/myparcelnl/magento/commit/6d1c461246cf382e7ada97d9ac3e5a0dc4f2d909))
* add sunday cut-off time ([#827](https://github.com/myparcelnl/magento/issues/827)) ([d0cfc65](https://github.com/myparcelnl/magento/commit/d0cfc650879a9c56fea7edb5895ef7784ffa8c7b))

## [4.12.1](https://github.com/myparcelnl/magento/compare/v4.12.0...v4.12.1) (2024-02-21)


### :bug: Bug Fixes

* fix php 8.2 deprecation error ([#818](https://github.com/myparcelnl/magento/issues/818)) ([793f7ef](https://github.com/myparcelnl/magento/commit/793f7ef4407d668ef6f81efc1860446b1e5c5680))
* use both address lines for street name ([#819](https://github.com/myparcelnl/magento/issues/819)) ([2053b08](https://github.com/myparcelnl/magento/commit/2053b08a61b5c4427887a6e2e144ad8da71d263e))

## [4.12.0](https://github.com/myparcelnl/magento/compare/v4.11.1...v4.12.0) (2024-02-01)


### :bug: Bug Fixes

* allow observer to use transport object ([#805](https://github.com/myparcelnl/magento/issues/805)) ([e64a687](https://github.com/myparcelnl/magento/commit/e64a68757491ac09b55e768f8ea21bb4afa62cf7))
* **checkout:** prevent delivery options from disappearing ([#811](https://github.com/myparcelnl/magento/issues/811)) ([1f153dc](https://github.com/myparcelnl/magento/commit/1f153dc0e3adca85de6b4cc91bf5e7269ac64554))
* **checkout:** prevent missing street from blocking delivery options ([#813](https://github.com/myparcelnl/magento/issues/813)) ([169e8be](https://github.com/myparcelnl/magento/commit/169e8be637f9d94c4e1f78f57d28e9fdca457c32))
* fix large format issue ([#798](https://github.com/myparcelnl/magento/issues/798)) ([dd98a32](https://github.com/myparcelnl/magento/commit/dd98a3295574cde8edf35adb36dd9fce33766281))
* fix php deprecation error ([#793](https://github.com/myparcelnl/magento/issues/793)) ([5222a77](https://github.com/myparcelnl/magento/commit/5222a77da6d153f74e41a323a97573c9f08f4a8a))
* **fulfilment:** export phonenumber and weight ([#810](https://github.com/myparcelnl/magento/issues/810)) ([d2b2a85](https://github.com/myparcelnl/magento/commit/d2b2a85193f3f3f7e7679c12d65e67211459145a))
* **fulfilment:** set weight on customs item rather than throw error ([#814](https://github.com/myparcelnl/magento/issues/814)) ([2eadcbc](https://github.com/myparcelnl/magento/commit/2eadcbcc7255c87c0d7dffa52d3d4fd41a67f1f9))
* implement regression feedback ([#806](https://github.com/myparcelnl/magento/issues/806)) ([0b3b1f0](https://github.com/myparcelnl/magento/commit/0b3b1f05fbd2e8a27d185d957da0f32d74ab2459))
* only set country if currentCountry has value ([#794](https://github.com/myparcelnl/magento/issues/794)) ([e86d3fe](https://github.com/myparcelnl/magento/commit/e86d3fedcfa969244b640d475ed5189872b2391f))


### :sparkles: New Features

* add carrier dpd ([#786](https://github.com/myparcelnl/magento/issues/786)) ([e87fe71](https://github.com/myparcelnl/magento/commit/e87fe71959a97b1222335828a5dd1022b22bf153))
* add carrier ups ([#787](https://github.com/myparcelnl/magento/issues/787)) ([c6aff00](https://github.com/myparcelnl/magento/commit/c6aff00e90236ff710423b643cfaeb8711cf8ab3))
* add digital stamp weight range ([#801](https://github.com/myparcelnl/magento/issues/801)) ([696b771](https://github.com/myparcelnl/magento/commit/696b771f06826eefbbc29d2a00a80a408653d34a))
* add state to consignment ([#815](https://github.com/myparcelnl/magento/issues/815)) ([8c3e208](https://github.com/myparcelnl/magento/commit/8c3e2086a2845e0323ce042690bf9dfde811581b))

## [4.11.1](https://github.com/myparcelnl/magento/compare/v4.11.0...v4.11.1) (2023-11-23)


### :bug: Bug Fixes

* **checkout:** show delivery options for tablerate when appropriate ([#790](https://github.com/myparcelnl/magento/issues/790)) ([8e29ac1](https://github.com/myparcelnl/magento/commit/8e29ac167466ba12b6b6d35da66127c5f1d94038))
* **ordernotes:** bail early without order ([#788](https://github.com/myparcelnl/magento/issues/788)) ([37bacb5](https://github.com/myparcelnl/magento/commit/37bacb59f58ab2f9ee96b40113028f6ed2af2dea)), closes [#772](https://github.com/myparcelnl/magento/issues/772)

## [4.11.0](https://github.com/myparcelnl/magento/compare/v4.10.0...v4.11.0) (2023-11-10)


### :sparkles: New Features

* add dhl carriers ([#777](https://github.com/myparcelnl/magento/issues/777)) ([6b64df9](https://github.com/myparcelnl/magento/commit/6b64df9c2151ac546e3dfc444b0c2181c8dbeed6))


### :bug: Bug Fixes

* **checkout:** consistently use correct package type for delivery options ([#782](https://github.com/myparcelnl/magento/issues/782)) ([85aa4c3](https://github.com/myparcelnl/magento/commit/85aa4c335b9a587f00673dfee337cd37c3980627))
* **checkout:** fix for multiple shipping methods ([#779](https://github.com/myparcelnl/magento/issues/779)) ([1acffbc](https://github.com/myparcelnl/magento/commit/1acffbc6ea35f71a329b2a428ec6bdd77d1d2586))
* **checkout:** restore payload extender functionality ([#775](https://github.com/myparcelnl/magento/issues/775)) ([15ffe5e](https://github.com/myparcelnl/magento/commit/15ffe5ea8a25198ef4e79352eacb14e56dcef0d1))
* **migration:** honor database table prefix ([#776](https://github.com/myparcelnl/magento/issues/776)) ([b327b90](https://github.com/myparcelnl/magento/commit/b327b90cc0d90a42cb65125df81cc451f96c9f55))
* **new-shipment-form:** preselect correct carrier ([#780](https://github.com/myparcelnl/magento/issues/780)) ([cc1817c](https://github.com/myparcelnl/magento/commit/cc1817c3034fcb6df69785601bad6b3b100e8228))
* **order-mode:** allow export with carrier specific options ([#781](https://github.com/myparcelnl/magento/issues/781)) ([6bef66b](https://github.com/myparcelnl/magento/commit/6bef66be54ea3db298eceed21858035ed8157780))

## [4.10.0](https://github.com/myparcelnl/magento/compare/v4.9.1...v4.10.0) (2023-08-01)


### :sparkles: New Features

* **fulfilment:** add order notes ([#768](https://github.com/myparcelnl/magento/issues/768)) ([d1fcdcc](https://github.com/myparcelnl/magento/commit/d1fcdcc84809564b4b30f6566acbcf1b43fdb2a7))


### :bug: Bug Fixes

* **migration:** fix deprecated functionality ([#766](https://github.com/myparcelnl/magento/issues/766)) ([5e18dad](https://github.com/myparcelnl/magento/commit/5e18dadd6adcd1f83d7a19dabd32d5bb027be0ba))

## [4.9.1](https://github.com/myparcelnl/magento/compare/v4.9.0...v4.9.1) (2023-04-20)


### :bug: Bug Fixes

* allow price based tablerate with mailbox ([#762](https://github.com/myparcelnl/magento/issues/762)) ([2860ad2](https://github.com/myparcelnl/magento/commit/2860ad2d49cc503281dba4a804287d609996149d))

## [4.9.0](https://github.com/myparcelnl/magento/compare/v4.8.1...v4.9.0) (2023-03-07)


### :bug: Bug Fixes

* restore option product not to use mailbox ([#757](https://github.com/myparcelnl/magento/issues/757)) ([31bfcf5](https://github.com/myparcelnl/magento/commit/31bfcf522c00b63268af8021f7aabe5bcedfbcff))


### :sparkles: New Features

* add insurance custom be ([#755](https://github.com/myparcelnl/magento/issues/755)) ([5525faf](https://github.com/myparcelnl/magento/commit/5525faf0bb61aaed42d740804aaae412609d35ee))

## [4.8.1](https://github.com/myparcelnl/magento/compare/v4.8.0...v4.8.1) (2023-02-02)


### :bug: Bug Fixes

* clarify texts regarding mailbox settings ([#745](https://github.com/myparcelnl/magento/issues/745)) ([8d33bd9](https://github.com/myparcelnl/magento/commit/8d33bd9bdd67e3fa1f50b62aad1c66d5d987ec8f))
* fix division by zero issue on checkout ([#746](https://github.com/myparcelnl/magento/issues/746)) ([cee3094](https://github.com/myparcelnl/magento/commit/cee3094e5ef04e89ee0de7a95b346afae588171f))
* fix php 8.1 deprecation notice in strtotime ([#748](https://github.com/myparcelnl/magento/issues/748)) ([eca47d6](https://github.com/myparcelnl/magento/commit/eca47d66ead8c8a1e28dad10bb94cb71120ea1b8))
* mailbox works with kilo setting on php 8.1 ([#744](https://github.com/myparcelnl/magento/issues/744)) ([721259a](https://github.com/myparcelnl/magento/commit/721259a5edf464bcec6bc516f271957da6a7f813))
* prevent type error in use entity id ([#749](https://github.com/myparcelnl/magento/issues/749)) ([57a05a7](https://github.com/myparcelnl/magento/commit/57a05a7f947a0fb5619e5734a6e497801fbefb45))
* use correct way to inject search value ([#750](https://github.com/myparcelnl/magento/issues/750)) ([d11f3e0](https://github.com/myparcelnl/magento/commit/d11f3e046e980272c2a22fae55be1d860be1ecb9))

## [4.8.0](https://github.com/myparcelnl/magento/compare/v4.7.1...v4.8.0) (2023-01-03)


### :bug: Bug Fixes

* fix division by zero when calculating package type ([#737](https://github.com/myparcelnl/magento/issues/737)) ([5811bc8](https://github.com/myparcelnl/magento/commit/5811bc8026564b2cccec99f7fc392ae770f92626))
* fix php 8.1 deprecation errors ([#736](https://github.com/myparcelnl/magento/issues/736)) ([81d49f1](https://github.com/myparcelnl/magento/commit/81d49f105e63192b19d38e2dcafb9c5d27e4c8c3))
* trim postal code before validating ([#735](https://github.com/myparcelnl/magento/issues/735)) ([c502947](https://github.com/myparcelnl/magento/commit/c502947644e801e5b75e04db93dcffec268ff317))


### :sparkles: New Features

* add eu insurance possibilities ([#733](https://github.com/myparcelnl/magento/issues/733)) ([1644a7b](https://github.com/myparcelnl/magento/commit/1644a7bb3df8446e707f114d91ef3409e69b8f25))

## [4.7.1](https://github.com/myparcelnl/magento/compare/v4.7.0...v4.7.1) (2022-12-12)


### :bug: Bug Fixes

* remove instabox ([#731](https://github.com/myparcelnl/magento/issues/731)) ([d1c66df](https://github.com/myparcelnl/magento/commit/d1c66df10fef13013803dd316521841331c579f5))
* remove myparcel product attributes on uninstall ([#722](https://github.com/myparcelnl/magento/issues/722)) ([00a17c0](https://github.com/myparcelnl/magento/commit/00a17c06d9e49aef6a3619548462e9117f11791c))
* remove php deprecation warning ([#721](https://github.com/myparcelnl/magento/issues/721)) ([23af9f8](https://github.com/myparcelnl/magento/commit/23af9f88d55d5e3c1c27563d3fec6fd8572ccb9b))

## [4.7.0](https://github.com/myparcelnl/magento/compare/v4.6.1...v4.7.0) (2022-10-05)


### :sparkles: New Features

* allow exporting digital stamp ([#716](https://github.com/myparcelnl/magento/issues/716)) ([793e78c](https://github.com/myparcelnl/magento/commit/793e78cc8d4cc12e0d453a0e7b8828ed29237784))
* show only relevant delivery options ([#711](https://github.com/myparcelnl/magento/issues/711)) ([1806558](https://github.com/myparcelnl/magento/commit/1806558ea9db450fc98c7e5df0e15fbae8e5a80f))


### :bug: Bug Fixes

* fix delivery options loading forever ([#713](https://github.com/myparcelnl/magento/issues/713)) ([6dc37e2](https://github.com/myparcelnl/magento/commit/6dc37e2a8a6c1159647a976c2f99694ba52577aa))
* fix mailbox amount ([#714](https://github.com/myparcelnl/magento/issues/714)) ([c9970f5](https://github.com/myparcelnl/magento/commit/c9970f56043accdecef7a9485131076297e0e7d3))
* translate instabox strings ([#718](https://github.com/myparcelnl/magento/issues/718)) ([a15b595](https://github.com/myparcelnl/magento/commit/a15b595dd032f9f95fea54e6638af45c65a42731))

## [4.6.1](https://github.com/myparcelnl/magento/compare/v4.6.0...v4.6.1) (2022-09-15)


### :bug: Bug Fixes

* display correct version to end user ([#706](https://github.com/myparcelnl/magento/issues/706)) ([a821359](https://github.com/myparcelnl/magento/commit/a82135985f96656c7d83781e1cdc8b0c29b7e1b7))
* fix wrong parameter type ([#707](https://github.com/myparcelnl/magento/issues/707)) ([57d79fa](https://github.com/myparcelnl/magento/commit/57d79facc56d33f7049d05441c2c955ae9da943d))
* have cronjob update shipment status ([#708](https://github.com/myparcelnl/magento/issues/708)) ([a615f6e](https://github.com/myparcelnl/magento/commit/a615f6e566848b8d20493dcb7664870d8e68bfaf))

## [4.6.0](https://github.com/myparcelnl/magento/compare/v4.5.0...v4.6.0) (2022-07-28)


### :sparkles: New Features

* allow custom amount of items in mailbox package ([#695](https://github.com/myparcelnl/magento/issues/695)) ([557f355](https://github.com/myparcelnl/magento/commit/557f355e9663882764c05e39623b57233d45881e))


### :bug: Bug Fixes

* compatibility with php 8.1 ([#690](https://github.com/myparcelnl/magento/issues/690)) ([027ee90](https://github.com/myparcelnl/magento/commit/027ee90d8105bbb6fca2ca18fe87a757cd1ecd63))
* error invalid element tooltip ([#698](https://github.com/myparcelnl/magento/issues/698)) ([6e0ea60](https://github.com/myparcelnl/magento/commit/6e0ea60fd0e2d1b090e3316d5d348fa44583c4ae))
* export order from order details ([#693](https://github.com/myparcelnl/magento/issues/693)) ([5d04892](https://github.com/myparcelnl/magento/commit/5d0489224464878bb5f4c58cbc76166dbe91d66f))
* **om:** show enable setting ([#691](https://github.com/myparcelnl/magento/issues/691)) ([0a72278](https://github.com/myparcelnl/magento/commit/0a722781044ab7975022c23a509614c30ddc8368))
* only get available options for carriers ([#703](https://github.com/myparcelnl/magento/issues/703)) ([f6317f7](https://github.com/myparcelnl/magento/commit/f6317f7b3bd0312220cff3c334f88e2d80410121)), closes [#701](https://github.com/myparcelnl/magento/issues/701)
* prevent loop while exporting shipment ([#700](https://github.com/myparcelnl/magento/issues/700)) ([ae83b0a](https://github.com/myparcelnl/magento/commit/ae83b0add4cb14dd037df495670190346aeebe1f))
* prevent upgrade from failing on new install ([#702](https://github.com/myparcelnl/magento/issues/702)) ([df24444](https://github.com/myparcelnl/magento/commit/df2444414e2d024fd1e08ed3a0e50367313e556e))

## [4.5.0](https://github.com/myparcelnl/magento/compare/v4.4.0...v4.5.0) (2022-06-14)


### :sparkles: New Features

* add custom insurance option ([#674](https://github.com/myparcelnl/magento/issues/674)) ([caaee09](https://github.com/myparcelnl/magento/commit/caaee09ee09740c0d24e2bcdff138e9024a9bd24))
* add instabox ([#662](https://github.com/myparcelnl/magento/issues/662)) ([d322919](https://github.com/myparcelnl/magento/commit/d3229192fc76a8a0e927254a68d0b9c558649cb0))
* update shipping status orderbeheer ([#677](https://github.com/myparcelnl/magento/issues/677)) ([2cbb2be](https://github.com/myparcelnl/magento/commit/2cbb2bee1a5dae4883b1ab5384a12da73682e1a3))


### :bug: Bug Fixes

* don't render delivery options when already visible ([#680](https://github.com/myparcelnl/magento/issues/680)) ([d5c084a](https://github.com/myparcelnl/magento/commit/d5c084ab138c322c1a973a9443c3761015086f5c))
* **om:** order grid shipment column options ([#681](https://github.com/myparcelnl/magento/issues/681)) ([cb7d2c0](https://github.com/myparcelnl/magento/commit/cb7d2c0d23377156ce65df2a104117dade981503))
* **regression:** mailbox price is baseprice by itself ([#686](https://github.com/myparcelnl/magento/issues/686)) ([c7d07ac](https://github.com/myparcelnl/magento/commit/c7d07ac48a1d9ca15b40aa13611e608184f904b6))
* **regression:** remove instabox pickup options ([#685](https://github.com/myparcelnl/magento/issues/685)) ([88f2020](https://github.com/myparcelnl/magento/commit/88f202069a0ac7ff702db7570f386afe76db1b01))
* set default carrier for row shipments ([#679](https://github.com/myparcelnl/magento/issues/679)) ([5f5437b](https://github.com/myparcelnl/magento/commit/5f5437baeadfa635a686565624a98a89b8c37892))
* set filterable and searchable attributes to false ([#668](https://github.com/myparcelnl/magento/issues/668)) ([7961df1](https://github.com/myparcelnl/magento/commit/7961df125bb5e1fffa37fd79d5ff8dbcb453a107))
