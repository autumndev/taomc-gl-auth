# GreenLight Auth plugin for Craft CMS 3.x

Bespoke authentication for GreenLight users

## Requirements

This plugin requires Craft CMS 3.0.0-beta.23 or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require /green-light-auth

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for GreenLight Auth.

## GreenLight Auth Overview

Allows the system to auto add group to user, auto activate the user and then redirect to the end portal.

## Configuring GreenLight Auth

Create new group: name = 'GREENLIGHT', handle = 'greenlight'.
env fiel - add `GREENLIGHT_REDIRECT_URL` as the url to redirect GL user to.

Brought to you by [autumn.dev](autumndev.co.uk)
