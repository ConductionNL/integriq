---
sidebar_position: 1
description: Get started with OpenConnector, the integration layer for Nextcloud. Connect REST, SOAP, GraphQL, files, and queues to typed OpenRegister stores.
---

# Introduction to OpenConnector

OpenConnector is a powerful Nextcloud application that enables seamless data synchronization between various sources and targets. It's designed to help you integrate your Nextcloud environment with external systems and the Open Register.

## Key Features

- **Source Integration**: Connect to various data sources
- **Target Synchronization**: Push data to multiple destinations
- **Open Register Support**: Native integration with Open Register
- **Flexible Mapping**: Configure how data should be transformed
- **Automated Sync**: Schedule and automate your data flows

## Main Components

### 📊 Dashboard
Get an overview of all your synchronization activities and system status.

### 🔌 Sources
Configure and manage your data sources:
- API endpoints
- File systems
- Databases
- External services

### 🎯 Endpoints
Define and manage API endpoints for data exchange.

### 👥 Consumers
Set up and manage data consumers that process your synchronized data.

### 🔄 Mappings
Create data transformation rules to map source data to target formats.

### ⚙️ Jobs
Schedule and monitor automated synchronization tasks.

### ☁️ Cloud Events
Handle and process cloud events for real-time data synchronization.

### 📝 Events
Configure event-driven data synchronization workflows.

### 🔄 Synchronization
Manage your synchronization processes and schedules.

### 📋 Rules
Define business rules and conditions for data synchronization.

### 👤 User API
Secure JSON-based API endpoints for user management and authentication:
- Get current user information
- Update user profiles  
- Secure user authentication with comprehensive protection
- See [User API Documentation](user-api.md) for complete reference

### ⚙️ Settings
Configure global settings for OpenConnector.

## Getting Started

To get started with OpenConnector, check out our [Getting Started Guide](getting-started) or dive into our [Tutorial](tutorial/installation).

# Open Register Documentation

This directory contains the documentation website for Open Register, built with [Docusaurus 2](https://docusaurus.io/).

## Installation

Install the dependencies:

    npm install

## Local Development

Start the development server:

    npm start

This command starts a local development server and opens up a browser window. Most changes are reflected live without having to restart the server.

## Build

Build the static files:

    npm run build

This command generates static content into the `build` directory and can be served using any static contents hosting service.

## Deployment

The documentation is automatically deployed to GitHub Pages when changes are pushed to the `documentation` branch.

## Project Structure

    website/
    ├── docs/              # Documentation markdown files
    ├── src/               # React components and pages
    ├── static/            # Static files (images, etc)
    ├── docusaurus.config.js   # Site configuration
    ├── package.json       # Project dependencies
    ├── README.md         # This file
    └── sidebars.js       # Sidebar configuration

## Contributing

1. Create a new branch from `documentation`
2. Make your changes
3. Test locally using `npm start`
4. Create a Pull Request to the `documentation` branch
