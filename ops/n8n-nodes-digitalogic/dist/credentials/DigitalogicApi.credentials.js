"use strict";

class DigitalogicApi {
  constructor() {
    this.name = "digitalogicApi";
    this.displayName = "Digitalogic Event Mesh";
    this.documentationUrl = "https://digitalogic.ir";
    this.properties = [
      {
        displayName: "API Base URL",
        name: "baseUrl",
        type: "string",
        default: "https://digitalogic.ir/wp-json/digitalogic/v1",
        required: true,
      },
      {
        displayName: "Event Key",
        name: "apiKey",
        type: "string",
        typeOptions: { password: true },
        default: "",
        required: true,
      },
    ];
    this.authenticate = {
      type: "generic",
      properties: {
        headers: {
          "X-Digitalogic-Event-Key": "={{$credentials.apiKey}}",
        },
      },
    };
    this.test = {
      request: {
        baseURL: "={{$credentials.baseUrl}}",
        url: "/event-mesh/presence",
        method: "GET",
      },
    };
  }
}

module.exports = { DigitalogicApi };
