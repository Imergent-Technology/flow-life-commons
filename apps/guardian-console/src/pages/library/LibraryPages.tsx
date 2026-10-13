import { AudienceLibraryPage } from './audience/AudienceLibraryPage.tsx'
import { AudiencePackPage } from './audience/AudiencePackPage.tsx'
import { useLibraryMode } from './libraryMode.ts'
import { ViewerLibraryPage } from './viewer/ViewerLibraryPage.tsx'
import { ViewerPackPage } from './viewer/ViewerPackPage.tsx'

// The two pages of `/resource-library`, each drawn by whichever reader the route chose (see LibraryRoute). This is the only place
// that names both readers; neither reader knows the other exists, and neither is handed the other's data.

export function LibraryHomePage() {
  return useLibraryMode().mode === 'viewer' ? <ViewerLibraryPage /> : <AudienceLibraryPage />
}

export function LibraryPackPage() {
  return useLibraryMode().mode === 'viewer' ? <ViewerPackPage /> : <AudiencePackPage />
}
