# Magento handleiding

Voor de handleiding en meer informatie, zie onze [Magento handleiding] op de [MyParcel Developer Portal].

[Magento handleiding]: https://developer.myparcel.nl/nl/documentatie/13.magento2.html
[MyParcel Developer Portal]: https://developer.myparcel.nl

## Installation

Install using composer require.

```bash
composer require myparcelnl/magento
```

## For developers

Clone this repository in `app/code/MyParcelNL/Magento/` using git and run the following commands in the root directory of your Magento installation:

```bash
composer config repositories.myparcelnl/magento path app/code/MyParcelNL/Magento/
composer require myparcelnl/magento
```

This will install our SDK as a dependency.

You can install the SDK as a git repository as well:
Clone MyParcelNL/Sdk in `app/code/MyParcelNL/Sdk/` using git and run the following commands in the root directory of your Magento installation:

```bash
composer config repositories.myparcelnl/sdk path app/code/MyParcelNL/Sdk/
composer require myparcelnl/sdk
```
