# xxllnc To Publication

This document outlines the connection between the [**xxllnc**](https://xxllnc.nl/teams/zaakgericht) **(Zaaksysteem) Search API** and the **Publication Register** (and its core object, the Publication object).

## Overview

The x**xllnc (Zaaksysteem)** is a system used by the Dutch government to manage and publish administrative decisions. Its Search API is a RESTful service that provides access to data within the xxllnc case system.

The **Publication Register** is a platform designed to manage and publish administrative decisions, with the **Publication object** as its central data element.

## Preparation

Before starting, ensure you have the following:

1.  Base URL of the Xxllnc Search API.
2. An installation of Open Register.
3. A configured instance of the Publication Register on Open Register.
4. An installation of Open Connector.

## Setting up the connection

Create a New Source:

1. Navigate to the "Sources" page in Open Connector.
 - Click Add Source and:
    - Set the Type to API.
    - Set the Location to the base URL of the Xxllnc Search API.
- No additional configuration is necessary, as the Search API is public by default.

![alt text](../../sources/image.png)

2. Test the Source:

- Open the source you just created.
- Click on Test in the action menu to open the test dialog.
- Press Test, and you should see the data available from the API.
If everything works correctly, note down the Source ID. You are now ready to set up a mapping.

![alt text](../../sources/image-1.png) ![alt text](../../sources/image-2.png)


## Setting up the mapping

Mappings are used to map data between two objects (e.g., from Object A to Object B, or vice versa).

For the Xxllnc Search API:

1. Map Data:
- Create a mapping between the Xxllnc Search API and the Publication Register's Publication object.
- Ensure you also map the Attachment object, as it is required for complete publication data.

Mappings ensure data consistency and enable smooth integration between the Xxllnc system and the Publication Register.
