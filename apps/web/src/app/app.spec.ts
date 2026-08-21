import { TestBed } from '@angular/core/testing';
import { provideZonelessChangeDetection } from '@angular/core';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { App } from './app';

describe('App', () => {
  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [App],
      providers: [
        provideZonelessChangeDetection(),
        provideRouter([]),
        provideHttpClient(),
        provideHttpClientTesting(),
      ],
    }).compileComponents();
  });

  it('renders the shell without waiting for navigation', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;

    expect(host.querySelector('ol-site-header')).toBeTruthy();
    expect(host.querySelector('ol-site-footer')).toBeTruthy();
    expect(host.querySelector('main#main')).toBeTruthy();
  });

  /**
   * Section 23.5 requires the skip link to be the first focusable element on
   * every page. It is easy to break by reordering the shell template, and
   * impossible to notice by looking.
   */
  it('puts the skip link first', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    const first = host.querySelector('a[href], button, input');

    expect(first?.getAttribute('href')).toBe('#main');
  });

  /**
   * A failed navigation request must degrade the megamenu, not the page.
   * Rendering nothing at all here would take out every category page at once.
   */
  it('survives a failed navigation fetch', () => {
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();

    const http = TestBed.inject(HttpTestingController);
    http.expectOne('/api/v1/navigation').error(new ProgressEvent('network error'));

    /*
     * The shell also loads the basket for the header badge. It is answered
     * with an empty basket rather than left open, because verify() treats an
     * outstanding request as a leak — and this one is expected.
     */
    http.expectOne('/api/v1/basket').flush({
      data: { items: [], line_count: 0, unit_count: 0 },
    });

    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    expect(host.querySelector('ol-site-footer')).toBeTruthy();

    http.verify();
  });
});
