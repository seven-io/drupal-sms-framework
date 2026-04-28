<p align="center">
  <img src="https://www.seven.io/wp-content/uploads/Logo.svg" width="250" alt="seven logo" />
</p>

<h1 align="center">seven Gateway for Drupal SMS Framework</h1>

<p align="center">
  Plug seven into the <a href="https://www.drupal.org/project/smsframework">Drupal SMS Framework</a> as an outbound and inbound SMS gateway.
</p>

<p align="center">
  <a href="LICENSE.txt"><img src="https://img.shields.io/badge/License-MIT-teal.svg" alt="MIT License" /></a>
  <img src="https://img.shields.io/badge/Drupal-9%20|%2010%20|%2011-blue" alt="Drupal 9 | 10 | 11" />
  <img src="https://img.shields.io/badge/SMS%20Framework-required-orange" alt="SMS Framework required" />
</p>

---

## Features

- **Outbound SMS Gateway** - Standard SMS Framework gateway, drop-in for any module that uses SMS Framework
- **Inbound SMS** - Forward incoming SMS via webhook to create or update Drupal entities
- **Custom Sender ID** - Configure an alphanumeric or numeric sender per gateway

## Prerequisites

- Drupal 9 / 10 / 11
- The [SMS Framework](https://www.drupal.org/project/smsframework) module installed and enabled
- A [seven account](https://www.seven.io/) with API key ([How to get your API key](https://help.seven.io/en/developer/where-do-i-find-my-api-key))

## Installation

1. Extract the [latest release](https://github.com/seven-io/drupal-sms-framework/releases/latest) into `/path/to/drupal/web/modules`.
2. In the Drupal admin go to **Extend > SEVEN SMS**, tick *seven SMS Module* and click **Install**.
3. Open **Configuration > SMS FRAMEWORK > Gateways** and click **+ Add gateway**.
4. Configure the seven gateway as shown:

   ![Add gateway](screenshots/add_gateway.png)

5. Paste your API key and (optionally) a sender ID, then save.

## Inbound SMS (optional)

To receive inbound SMS as Drupal events:

1. In the seven [dashboard](https://app.seven.io/) under *Developer > Webhooks* add an inbound-SMS webhook.
2. Point the webhook URL at `/<your-drupal>/sms/receive/seven`:

   ![Create webhook](screenshots/seven_create_webhook.png)

Drupal events fire on every incoming message and can be consumed by Rules, Webform handlers, or any custom module.

## Support

Need help? Feel free to [contact us](https://www.seven.io/en/company/contact/) or [open an issue](https://github.com/seven-io/drupal-sms-framework/issues).

## License

[MIT](LICENSE.txt)
