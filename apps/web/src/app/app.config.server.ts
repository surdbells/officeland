import { mergeApplicationConfig, ApplicationConfig } from '@angular/core';
import { provideServerRendering, withRoutes } from '@angular/ssr';
import { FetchBackend, HttpBackend } from '@angular/common/http';
import { appConfig } from './app.config';
import { serverRoutes } from './app.routes.server';
import { ServerApiBackend } from './core/api/server-api-backend';

const serverConfig: ApplicationConfig = {
  providers: [
    provideServerRendering(withRoutes(serverRoutes)),

    // Relative '/api/...' URLs are not resolvable in Node. See
    // ServerApiBackend for why the rewrite happens here rather than in an
    // interceptor. FetchBackend must be provided explicitly because it is
    // being injected as a delegate rather than used as the backend itself.
    FetchBackend,
    { provide: HttpBackend, useClass: ServerApiBackend },
  ],
};

export const config = mergeApplicationConfig(appConfig, serverConfig);
