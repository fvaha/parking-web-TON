import React, { useEffect, useRef, useState, useCallback } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet-routing-machine';
import 'leaflet-routing-machine/dist/leaflet-routing-machine.css';
import type { ParkingSpace, Sensor } from '../types';
import { LanguageService } from '../services/language_service';
import { Map, Globe, X, Info, Layers, Moon, Sun, Navigation, Satellite } from 'lucide-react';
import { MAPS_CONFIG, TILE_LAYERS, type MapStyle } from '../config/maps_config';

// Fix for default marker icons in Leaflet with Vite
// Use CDN URLs for marker icons
// Since we're using divIcon for custom markers, we don't need default icons
// But we'll set a fallback in case default markers are used
try {
  const IconDefault = (L as any).Icon.Default;
  if (IconDefault) {
    delete (IconDefault.prototype as any)._getIconUrl;
    IconDefault.mergeOptions({
      iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
      iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
      shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
    });
  }
} catch (e) {
  console.warn('Could not set default Leaflet icons:', e);
}

interface ParkingMapProps {
  parking_spaces: ParkingSpace[];
  sensors: Sensor[];
  on_space_click: (space: ParkingSpace) => void;
  show_reservation_modal: boolean;
  selected_space: ParkingSpace | null;
  license_plate: string;
  on_reserve_space: (space: ParkingSpace) => void;
  on_close_reservation_modal: () => void;
}

export const ParkingMap: React.FC<ParkingMapProps> = ({ 
  parking_spaces, 
  sensors, 
  on_space_click
}) => {
  const map_ref = useRef<HTMLDivElement>(null);
  const map_initialized_ref = useRef<boolean>(false);
  const [map, set_map] = useState<any>(null); // L.Map
  const [markers, set_markers] = useState<any[]>([]); // L.Marker[]
  const [rectangles, set_rectangles] = useState<any[]>([]); // L.Rectangle[]
  const markers_ref = useRef<any[]>([]); // Ref to track markers for cleanup
  const rectangles_ref = useRef<any[]>([]); // Ref to track rectangles for cleanup
  const last_processed_data_ref = useRef<string>(''); // Ref to track if data has changed
  const is_processing_ref = useRef<boolean>(false); // Ref to prevent concurrent processing
  const on_space_click_ref = useRef(on_space_click); // Ref to store callback function
  const show_routing_options_ref = useRef<any>(null); // Ref to store routing function
  const [routing_control, set_routing_control] = useState<any>(null); // L.Routing.Control
  const [map_style, set_map_style] = useState<MapStyle>('light');
  const [show_map_menu, set_show_map_menu] = useState<boolean>(false);
  const [use_google_maps, set_use_google_maps] = useState<boolean>(false);
  const [show_legend, set_show_legend] = useState<boolean>(false);
  const [show_route_selector, set_show_route_selector] = useState<boolean>(false);
  const [selected_route_index, set_selected_route_index] = useState<number | null>(null);
  const [route_alternatives, set_route_alternatives] = useState<any[]>([]);

  const [route_lines, set_route_lines] = useState<any[]>([]); // L.Polyline[]
  const [user_location] = useState<L.LatLng>(
    L.latLng(MAPS_CONFIG.USER_LOCATION.lat, MAPS_CONFIG.USER_LOCATION.lng)
  );
  
  const language_service = LanguageService.getInstance();

  // Update callback refs when they change
  useEffect(() => {
    on_space_click_ref.current = on_space_click;
  }, [on_space_click]);

  const generate_rectangle_bounds = useCallback((center_coords: { lat: number; lng: number }) => {
    // Create a consistent, reusable rectangle size for all parking spaces
    // This ensures uniform appearance across the map
    const lat_offset = 0.000009; // Half height of rectangle
    const lng_offset = 0.000025; // Half width of rectangle
    
    return [
      [center_coords.lat - lat_offset, center_coords.lng - lng_offset] as [number, number],
      [center_coords.lat + lat_offset, center_coords.lng + lng_offset] as [number, number]
    ];
  }, []);

  const get_status_color = useCallback((status: string): string => {
    switch (status) {
      case 'occupied': return '#FF0000';
      case 'reserved': return '#FFA500';
      case 'vacant': return '#00FF00';
      default: return '#808080';
    }
  }, []);

  // Reusable function to create parking space polygon
  const create_parking_space_polygon = useCallback((center_coords: { lat: number; lng: number }, status: string) => {
    const bounds = generate_rectangle_bounds(center_coords);
    return L.rectangle(bounds, {
      color: '#000000',
      weight: 2,
      fillColor: get_status_color(status),
      fillOpacity: 0.35
    });
  }, [generate_rectangle_bounds, get_status_color]);

  const show_routing_options = useCallback((start: L.LatLng, end: L.LatLng) => {
    // Clear existing routing
    if (routing_control) {
      map?.removeControl(routing_control);
      set_routing_control(null);
    }

    try {
      // Check if L.Routing is available (from leaflet-routing-machine)
      if (typeof (L as any).Routing === 'undefined') {
        console.warn('Leaflet Routing Machine not available');
        // Fallback: just show a line between points
        const routeLine = L.polyline([start, end], {
          color: '#10B981', // Green for fastest route
          weight: 6,
          opacity: 0.8
        }).addTo(map!);
        
        // Add a simple close button
        const CloseButtonControl = (L.Control as any).extend({
          onAdd: function() {
            const div = L.DomUtil.create('div', 'route-close-control');
            div.innerHTML = `
              <button style="
                background: rgba(255, 255, 255, 0.9);
                border: none;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                font-size: 18px;
                font-weight: bold;
                color: #000;
                cursor: pointer;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
              ">×</button>
            `;
            
            div.querySelector('button')?.addEventListener('click', () => {
              map?.removeLayer(routeLine);
              map?.removeControl(closeButton);
            });
            
            return div;
          }
        });
        
        const closeButton = new CloseButtonControl();
        closeButton.addTo(map!);
        return;
      }

      // Create routing control with multiple alternatives
      // Use OSRM routing service (free and open source)
      let routing: any;
      try {
        // Try to use OSRM router if available
        const Routing = (L as any).Routing;
        if (Routing && Routing.osrmv1) {
          routing = Routing.control({
            waypoints: [start, end],
            routeWhileDragging: false,
            showAlternatives: true, // Show multiple route alternatives
            fitSelectedRoutes: false, // Don't automatically zoom to routes
            router: Routing.osrmv1({
              serviceUrl: 'https://router.project-osrm.org/route/v1',
              profile: 'driving'
            }),
            lineOptions: {
              styles: [
                { color: '#10B981', opacity: 0.8, weight: 6 }, // Primary route (green - fastest)
                { color: '#3B82F6', opacity: 0.6, weight: 4 }, // Alternative 1 (blue - medium)
                { color: '#EF4444', opacity: 0.6, weight: 4 }, // Alternative 2 (red - longest)
                { color: '#8B5CF6', opacity: 0.6, weight: 4 }  // Alternative 3 (purple - longest)
              ],
              extendToWaypoints: false,
              missingRouteTolerance: 0
            }
          }).addTo(map!);
        } else {
          // Fallback: use default routing (GraphHopper or other)
          routing = Routing.control({
            waypoints: [start, end],
            routeWhileDragging: false,
            showAlternatives: true,
            fitSelectedRoutes: false,
            lineOptions: {
              styles: [
                { color: '#10B981', opacity: 0.8, weight: 6 },
                { color: '#3B82F6', opacity: 0.6, weight: 4 },
                { color: '#EF4444', opacity: 0.6, weight: 4 },
                { color: '#8B5CF6', opacity: 0.6, weight: 4 }
              ],
              extendToWaypoints: false,
              missingRouteTolerance: 0
            }
          }).addTo(map!);
        }
      } catch (error) {
        console.error('Error creating routing control:', error);
        // Fallback to simple line
        const routeLine = L.polyline([start, end], {
          color: '#10B981',
          weight: 6,
          opacity: 0.8
        }).addTo(map!);
        
        const CloseButtonControl = (L.Control as any).extend({
          onAdd: function() {
            const div = L.DomUtil.create('div', 'route-close-control');
            div.innerHTML = `
              <button style="
                background: rgba(255, 255, 255, 0.9);
                border: none;
                border-radius: 50%;
                width: 30px;
                height: 30px;
                font-size: 18px;
                font-weight: bold;
                color: #000;
                cursor: pointer;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
              ">×</button>
            `;
            
            div.querySelector('button')?.addEventListener('click', () => {
              map?.removeLayer(routeLine);
              map?.removeControl(closeButton);
            });
            
            return div;
          }
        });
        
        const closeButton = new CloseButtonControl();
        closeButton.addTo(map!);
        return;
      }

      // Wait for routes to be calculated, then show route selector
      routing.on('routesfound', (e: any) => {
        const routes = e.routes;
        set_route_alternatives(routes);
        set_show_route_selector(true);
        set_selected_route_index(0); // Select first route by default

        
        // Store route lines for coloring
        setTimeout(() => {
          const routeLines: any[] = []; // L.Polyline[]
          map?.eachLayer((layer: any) => {
            // Check if layer is a polyline with color
            if (layer && layer.options && layer.options.color) {
              routeLines.push(layer);
            }
          });
          set_route_lines(routeLines);
        }, 200);
        
        // Hide the routing control text initially
        setTimeout(() => {
          const routingContainer = document.querySelector('.leaflet-routing-container');
          if (routingContainer) {
            (routingContainer as HTMLElement).style.display = 'none';
          }
        }, 100);
      });

      // Add custom close button to the routing control
      setTimeout(() => {
        const routingContainer = document.querySelector('.leaflet-routing-container');
        if (routingContainer) {
          const closeButton = document.createElement('button');
          closeButton.innerHTML = '×';
          closeButton.style.cssText = `
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(255, 255, 255, 0.9);
            border: none;
            font-size: 20px;
            font-weight: bold;
            color: #000;
            cursor: pointer;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1001;
            border-radius: 50%;
            line-height: 1;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
          `;
          
          closeButton.addEventListener('click', () => {
            map?.removeControl(routing);
            set_routing_control(null);
            set_show_route_selector(false);
            set_selected_route_index(null);
            set_route_alternatives([]);
            set_route_lines([]); // Clear stored route lines
          });
          
          routingContainer.appendChild(closeButton);
        }
      }, 100);

      set_routing_control(routing);
    } catch (error) {
      console.error('Error creating routing control:', error);
      // Fallback: show simple line
      const routeLine = L.polyline([start, end], {
        color: '#10B981', // Green for fastest route
        weight: 6,
        opacity: 0.8
      }).addTo(map!);
      
      // Add simple close button
      const CloseButtonControl = (L.Control as any).extend({
        onAdd: function() {
          const div = L.DomUtil.create('div', 'route-close-control');
          div.innerHTML = `
            <button style="
              background: rgba(255, 255, 255, 0.9);
              border: none;
              border-radius: 50%;
              width: 30px;
              height: 30px;
              font-size: 18px;
              font-weight: bold;
              color: #000;
              cursor: pointer;
              box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
            ">×</button>
          `;
          
          div.querySelector('button')?.addEventListener('click', () => {
            map?.removeLayer(routeLine);
            map?.removeControl(closeButton);
          });
          
          return div;
        }
      });
      
      const closeButton = new CloseButtonControl();
      closeButton.addTo(map!);
    }
  }, [routing_control, map]);

  // Update routing function ref when it changes
  useEffect(() => {
    show_routing_options_ref.current = show_routing_options;
  }, [show_routing_options]);

  const select_route = useCallback((routeIndex: number) => {
    set_selected_route_index(routeIndex);
    
    // Only change the route color on the map - nothing else
    if (route_lines.length > 0) {
      route_lines.forEach((line, index) => {
        if (index === routeIndex) {
          // Selected route: green
          line.setStyle({ color: '#10B981', weight: 6, opacity: 0.8 });
        } else {
          // Other routes: blue
          line.setStyle({ color: '#3B82F6', weight: 4, opacity: 0.6 });
        }
      });
    }
  }, [route_lines]);



  const close_route_selector = useCallback(() => {
    set_show_route_selector(false);
    set_selected_route_index(null);
    set_route_alternatives([]);

    set_route_lines([]); // Clear stored route lines
    if (routing_control) {
      map?.removeControl(routing_control);
      set_routing_control(null);
    }
  }, [routing_control, map]);

  // Change map style
  const change_map_style = useCallback((style: MapStyle) => {
    set_map_style(style);
    set_show_map_menu(false);
    
    // Update use_google_maps state based on style
    // Google Maps styles: streets, hybrid, satellite
    // OSM styles: light, dark
    set_use_google_maps(style === 'streets' || style === 'hybrid' || style === 'satellite');
    
    if (map) {
      // Remove all existing tile layers
      map.eachLayer((layer) => {
        if (layer instanceof L.TileLayer) {
          map.removeLayer(layer);
        }
      });

      // Add new tile layer based on selected style
      const tile_config = TILE_LAYERS[style];
      const new_layer = L.tileLayer(tile_config.url, {
        attribution: '',
        maxZoom: 19,
        minZoom: 1
      }).addTo(map);
      
      // CRITICAL: Invalidate size after switching tile layers
      setTimeout(() => {
        try {
          if (map && map_ref.current && map_ref.current.offsetWidth > 0 && map_ref.current.offsetHeight > 0) {
            map.invalidateSize();
            map.eachLayer((layer: any) => {
              if (layer && typeof layer.redraw === 'function') {
                layer.redraw();
              }
            });
          }
        } catch (error) {
          console.warn('Error invalidating map after tile switch:', error);
        }
      }, 100);
    }
  }, [map]);

  // Toggle between OSM and Google Maps
  const toggle_map_provider = useCallback(() => {
    set_use_google_maps(prev => {
      const new_provider = !prev;
      // Switch to Google Maps (streets) or OSM (light)
      change_map_style(new_provider ? 'streets' : 'light');
      return new_provider;
    });
  }, [change_map_style]);

  const toggle_legend = useCallback(() => {
    set_show_legend(prev => !prev);
  }, []);

  const toggle_directions_for_route = useCallback((routeIndex: number) => {
    if (routing_control) {
      const routingContainer = document.querySelector('.leaflet-routing-container');
      if (routingContainer) {
        // Toggle visibility of the routing container
        const isVisible = (routingContainer as HTMLElement).style.display !== 'none';
        
        if (isVisible) {
          // Hide all directions
          (routingContainer as HTMLElement).style.display = 'none';
        } else {
          // Show directions for the specific route
          (routingContainer as HTMLElement).style.display = 'block';
          
          // Show only the selected route's directions
          const routeElements = routingContainer.querySelectorAll('.leaflet-routing-alt');
          routeElements.forEach((element, index) => {
            if (index === routeIndex) {
              (element as HTMLElement).style.display = 'block';
            } else {
              (element as HTMLElement).style.display = 'none';
            }
          });
          
          // Expand the routing container to show full directions
          (routingContainer as HTMLElement).style.maxHeight = 'none';
          (routingContainer as HTMLElement).style.overflow = 'visible';
        }
      }
    }
  }, [routing_control]);

  // Initialize map only once
  useEffect(() => {
    const container = map_ref.current;
    if (!container) {
      return;
    }

    if (map_initialized_ref.current) {
      return;
    }

    // Check if container already has a map (using a safer approach)
    if (container.querySelector('.leaflet-container')) {
      map_initialized_ref.current = true; // Mark as initialized to prevent re-init
      return;
    }

    // Log only if dimensions are missing
    if (container.offsetWidth === 0 || container.offsetHeight === 0) {
      console.log('Initializing map, container dimensions:', {
        width: container.offsetWidth,
        height: container.offsetHeight,
        clientWidth: container.clientWidth,
        clientHeight: container.clientHeight
      });
    }

    // Function to initialize the map
    const initializeMap = () => {
      if (map_initialized_ref.current || !container) {
        return;
      }

      // Double-check dimensions
      if (container.offsetWidth === 0 || container.offsetHeight === 0) {
        return;
      }

      map_initialized_ref.current = true;
      // Calculate center point that includes all parking spaces
      // Use a wider area to show all streets with parking spaces
      const center_coords = {
        lat: 43.1400000000000, // Center between all parking spaces
        lng: 20.5175000000000  // Center between all parking spaces
      };
      
      try {
        // Use a much lower zoom level to show more area (zoomed out)
        const new_map = L.map(container, {
          attributionControl: false, // Remove Leaflet attribution
          preferCanvas: false // Use DOM rendering for better compatibility
        }).setView([center_coords.lat, center_coords.lng], 15); // Zoom out to show more area (lower number = more zoomed out)
        
        // Add initial tile layer based on map_style
        const initial_tile_config = TILE_LAYERS[map_style];
        const initial_layer = L.tileLayer(initial_tile_config.url, {
          attribution: '', // Remove attribution text
          maxZoom: 19,
          minZoom: 1
        }).addTo(new_map);

        set_map(new_map);

        // CRITICAL: Invalidate map size multiple times to ensure proper rendering
        // Leaflet needs this when container dimensions change or are not immediately available
        const invalidateMap = () => {
          if (!new_map || !container) {
            return;
          }
          
          // Check if container has valid dimensions before invalidating
          const width = container.offsetWidth;
          const height = container.offsetHeight;
          
          if (width === 0 || height === 0) {
            // Don't invalidate if container has no dimensions - this causes errors
            return;
          }
          
          try {
            new_map.invalidateSize();
            // Force a redraw of tile layers
            new_map.eachLayer((layer: any) => {
              if (layer && typeof layer.redraw === 'function') {
                layer.redraw();
              }
            });
          } catch (error) {
            // Silently handle errors - map might be in transition
          }
        };

        // Use requestAnimationFrame for better timing
        requestAnimationFrame(() => {
          invalidateMap();
        });

        // Invalidate after short delays to catch layout changes
        setTimeout(invalidateMap, 100);
        setTimeout(invalidateMap, 500);

        // Also invalidate when window resizes (with debounce)
        let resizeTimer: ReturnType<typeof setTimeout> | null = null;
        const handleResize = () => {
          if (resizeTimer) {
            clearTimeout(resizeTimer);
          }
          resizeTimer = setTimeout(() => {
            invalidateMap();
          }, 150);
        };
        window.addEventListener('resize', handleResize);

        // Add user location marker since we have hardcoded coordinates
        L.marker(user_location, {
          icon: L.divIcon({
            className: 'user-location-marker',
            html: '<div style="background-color: #4285F4; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 6px rgba(0,0,0,0.3);"></div>',
            iconSize: [20, 20],
            iconAnchor: [10, 10]
          })
        }).addTo(new_map).bindPopup('Your Location');

        // Return cleanup function from initializeMap
        return () => {
          window.removeEventListener('resize', handleResize);
          if (resizeTimer) {
            clearTimeout(resizeTimer);
          }
          if (new_map) {
            try {
              new_map.remove();
            } catch (error) {
              console.warn('Error removing map:', error);
            }
            set_map(null);
            map_initialized_ref.current = false;
          }
        };
      } catch (error) {
        console.error('Error initializing map:', error);
        map_initialized_ref.current = false;
        return null;
      }
    };

    // Store cleanup function from map initialization
    let mapCleanup: (() => void) | null = null;

    // Ensure container has dimensions - if not, wait a bit
    if (container.offsetWidth === 0 || container.offsetHeight === 0) {
      
      let checkCount = 0;
      const maxChecks = 20; // Max 2 seconds (20 * 100ms)
      
      // Use requestAnimationFrame for better timing
      const checkDimensions = () => {
        checkCount++;
        if (checkCount > maxChecks) {
          return; // Abort silently after max checks
        }
        
        if (container.offsetWidth > 0 && container.offsetHeight > 0 && !map_initialized_ref.current) {
          const cleanup = initializeMap();
          if (cleanup) {
            mapCleanup = cleanup;
          }
        } else if (!map_initialized_ref.current) {
          requestAnimationFrame(checkDimensions);
        }
      };
      requestAnimationFrame(checkDimensions);
      
      // Also try with setTimeout as fallback
      const timer = setTimeout(() => {
        if (container.offsetWidth > 0 && container.offsetHeight > 0 && !map_initialized_ref.current) {
          const cleanup = initializeMap();
          if (cleanup) {
            mapCleanup = cleanup;
          }
        }
      }, 500);
      
      // Return cleanup function for useEffect
      return () => {
        clearTimeout(timer);
        if (mapCleanup) {
          mapCleanup();
        }
      };
    } else {
      // Container has dimensions, initialize immediately
      const cleanup = initializeMap();
      if (cleanup) {
        mapCleanup = cleanup;
      }
      
      // Return cleanup function for useEffect
      return () => {
        if (mapCleanup) {
          mapCleanup();
        }
      };
    }

  }, []); // Empty dependency array - only run once

  // Handle parking spaces and sensors updates
  useEffect(() => {
    // Multiple guard checks to prevent infinite loops and ensure robustness
    if (!map) {
      return;
    }

    if (parking_spaces.length === 0) {
      return;
    }

    if (sensors.length === 0) {
      return;
    }

    // Prevent concurrent processing - critical guard to prevent loops
    if (is_processing_ref.current) {
      return;
    }

    // Create a hash of current data to check if it has changed
    const data_hash = JSON.stringify({
      spaces: parking_spaces.map(s => ({ id: s.id, status: s.status, sensor_id: s.sensor_id })),
      sensors: sensors.map(s => ({ id: s.id, lat: s.coordinates?.lat, lng: s.coordinates?.lng }))
    });

    // Skip if data hasn't changed - prevents unnecessary re-renders
    if (last_processed_data_ref.current === data_hash) {
      return;
    }

    // Set processing flag immediately to prevent concurrent execution
    is_processing_ref.current = true;

    // Update the hash immediately to prevent re-processing
    last_processed_data_ref.current = data_hash;

    // Clear existing markers and rectangles using refs
    markers_ref.current.forEach(marker => {
      try {
        map.removeLayer(marker);
      } catch (e) {
        console.warn('Error removing marker:', e);
      }
    });
    rectangles_ref.current.forEach(rect => {
      try {
        map.removeLayer(rect);
      } catch (e) {
        console.warn('Error removing rectangle:', e);
      }
    });
    
    // Clear refs
    markers_ref.current = [];
    rectangles_ref.current = [];

    const new_markers: any[] = []; // L.Marker[]
    const new_rectangles: any[] = []; // L.Rectangle[]

    // Group parking spaces by street/area for counting
    const street_counts: { [key: string]: number } = {};
    const street_centers: { [key: string]: { lat: number; lng: number } } = {};
    const street_names: { [key: string]: string } = {};

    parking_spaces.forEach((space, index) => {
      const sensor = sensors.find(s => s.id === space.sensor_id);
      if (!sensor) {
        console.warn(`Sensor not found for parking space ${space.id} with sensor_id ${space.sensor_id}`);
        return;
      }

      if (!sensor.coordinates || !sensor.coordinates.lat || !sensor.coordinates.lng) {
        console.warn(`Invalid coordinates for sensor ${sensor.id}`);
        return;
      }

      try {
        // Create parking space rectangle
        const rectangle = create_parking_space_polygon(sensor.coordinates, space.status);
        rectangle.addTo(map);

        // Create marker at the center of the rectangle
        const center_lat = sensor.coordinates.lat;
        const center_lng = sensor.coordinates.lng;
        const marker = L.marker([center_lat, center_lng], {
          icon: L.divIcon({
            className: 'parking-marker',
            html: `<div style="background-color: ${get_status_color(space.status)}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">PS${index + 1}</div>`,
            iconSize: [40, 20],
            iconAnchor: [20, 10]
          })
        }).addTo(map);

        // Add click listeners using refs to avoid dependency issues
        rectangle.on('click', () => {
          on_space_click_ref.current(space);
        });
        
        // Single click handler for marker
        marker.on('click', () => {
          on_space_click_ref.current(space);
        });

        // Add routing option on marker double-click (to avoid conflict with single click)
        let clickTimer: ReturnType<typeof setTimeout> | null = null;
        marker.on('dblclick', () => {
          if (user_location && show_routing_options_ref.current) {
            const destination = L.latLng(center_lat, center_lng);
            show_routing_options_ref.current(user_location, destination);
          }
        });

        new_rectangles.push(rectangle);
        new_markers.push(marker);

        // Count parking spaces by street name for better grouping
        const street_key = sensor.street_name || 'Unknown';
        if (!street_counts[street_key]) {
          street_counts[street_key] = 0;
          street_centers[street_key] = sensor.coordinates;
          street_names[street_key] = sensor.street_name || 'Unknown';
        }
        street_counts[street_key]++;
      } catch (error) {
        console.error(`Error creating parking space ${space.id}:`, error);
      }
    });

    // Update refs and state
    markers_ref.current = new_markers;
    rectangles_ref.current = new_rectangles;
    set_markers(new_markers);
    set_rectangles(new_rectangles);

    // Fit map bounds to show all parking spaces only if no routes are active
    if (new_rectangles.length > 0 && !show_route_selector) {
      try {
        const bounds = L.latLngBounds([]);
        new_rectangles.forEach(rect => {
          bounds.extend(rect.getBounds());
        });
        
        // Add some padding around the bounds
        map.fitBounds(bounds, { 
          padding: [20, 20],
          maxZoom: MAPS_CONFIG.DEFAULT_ZOOM - 1 // Don't zoom in too much
        });
      } catch (error) {
        console.error('Error fitting bounds:', error);
      }
    }

    // Invalidate map size after adding layers
    setTimeout(() => {
      try {
        if (map && map_ref.current && map_ref.current.offsetWidth > 0 && map_ref.current.offsetHeight > 0) {
          map.invalidateSize();
        }
      } catch (error) {
        console.warn('Error invalidating map size:', error);
      }
      // Reset processing flag after a delay
      is_processing_ref.current = false;
    }, 100);
  }, [map, parking_spaces, sensors, user_location, generate_rectangle_bounds, get_status_color, show_route_selector, create_parking_space_polygon]);

  // Cleanup routing control when component unmounts
  useEffect(() => {
    return () => {
      if (routing_control && map) {
        map.removeControl(routing_control);
      }
    };
  }, [routing_control, map]);

  // Update map style when map_style changes
  useEffect(() => {
    if (map && map_style) {
      // Remove all existing tile layers
      map.eachLayer((layer) => {
        if (layer instanceof L.TileLayer) {
          map.removeLayer(layer);
        }
      });

      // Add new tile layer based on selected style
      const tile_config = TILE_LAYERS[map_style];
      const new_layer = L.tileLayer(tile_config.url, {
        attribution: '',
        maxZoom: 19,
        minZoom: 1
      }).addTo(map);
      
      // Invalidate size after switching tile layers
      setTimeout(() => {
        try {
          if (map && map_ref.current && map_ref.current.offsetWidth > 0 && map_ref.current.offsetHeight > 0) {
            map.invalidateSize();
          }
        } catch (error) {
          console.warn('Error invalidating map after style change:', error);
        }
      }, 100);
    }
  }, [map, map_style]);

  // Get icon for map style
  const get_map_style_icon = (style: MapStyle) => {
    switch (style) {
      case 'light':
        return <Sun size={18} />;
      case 'dark':
        return <Moon size={18} />;
      case 'streets':
        return <Navigation size={18} />;
      case 'hybrid':
        return <Layers size={18} />;
      case 'satellite':
        return <Satellite size={18} />;
      default:
        return <Map size={18} />;
    }
  };

  return (
    <div className="parking-map" style={{ position: 'relative' }}>
      {/* Map Provider Toggle - Top right corner */}
      <div className="map-toggle-overlay">
        <button 
          className="map-toggle-transparent"
          onClick={toggle_map_provider}
          title={use_google_maps ? language_service.t('switch_to_osm') || 'Switch to OpenStreetMap' : language_service.t('switch_to_google_maps') || 'Switch to Google Maps'}
        >
          {use_google_maps ? <Globe size={20} /> : <Map size={20} />}
        </button>
      </div>

      {/* Map Style Menu - Bottom right corner */}
      <div className="map-style-menu-container" style={{
        position: 'absolute',
        bottom: '20px',
        right: '20px',
        zIndex: 1000,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'flex-end',
        gap: '0.5rem'
      }}>
        {/* Style options menu */}
        {show_map_menu && (
          <div className="map-style-menu" style={{
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            borderRadius: '12px',
            padding: '0.5rem',
            boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
            backdropFilter: 'blur(10px)',
            minWidth: '160px',
            display: 'flex',
            flexDirection: 'column',
            gap: '0.25rem'
          }}>
            {(['light', 'dark', 'streets', 'hybrid', 'satellite'] as MapStyle[]).map((style) => (
              <button
                key={style}
                onClick={() => change_map_style(style)}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: '0.75rem',
                  padding: '0.6rem 1rem',
                  border: 'none',
                  borderRadius: '8px',
                  backgroundColor: map_style === style ? 'rgba(37, 99, 235, 0.1)' : 'transparent',
                  color: map_style === style ? '#2563eb' : '#374151',
                  cursor: 'pointer',
                  fontSize: '0.9rem',
                  fontWeight: map_style === style ? '600' : '500',
                  transition: 'all 0.2s ease',
                  textAlign: 'left',
                  width: '100%'
                }}
                onMouseEnter={(e) => {
                  if (map_style !== style) {
                    e.currentTarget.style.backgroundColor = 'rgba(0, 0, 0, 0.05)';
                  }
                }}
                onMouseLeave={(e) => {
                  if (map_style !== style) {
                    e.currentTarget.style.backgroundColor = 'transparent';
                  }
                }}
              >
                <span style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: '20px' }}>
                  {get_map_style_icon(style)}
                </span>
                <span style={{ textTransform: 'capitalize' }}>{TILE_LAYERS[style].name}</span>
                {map_style === style && (
                  <span style={{ marginLeft: 'auto', fontSize: '0.75rem', color: '#2563eb' }}>✓</span>
                )}
              </button>
            ))}
          </div>
        )}
        
        {/* Toggle button */}
        <button
          onClick={() => set_show_map_menu(!show_map_menu)}
          style={{
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            border: '2px solid rgba(0, 0, 0, 0.1)',
            borderRadius: '12px',
            padding: '0.75rem',
            cursor: 'pointer',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: '0 4px 12px rgba(0, 0, 0, 0.15)',
            backdropFilter: 'blur(10px)',
            transition: 'all 0.3s ease',
            width: '48px',
            height: '48px'
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.backgroundColor = '#ffffff';
            e.currentTarget.style.transform = 'scale(1.05)';
            e.currentTarget.style.boxShadow = '0 6px 16px rgba(0, 0, 0, 0.2)';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.backgroundColor = 'rgba(255, 255, 255, 0.95)';
            e.currentTarget.style.transform = 'scale(1)';
            e.currentTarget.style.boxShadow = '0 4px 12px rgba(0, 0, 0, 0.15)';
          }}
          title="Change map style"
        >
          {get_map_style_icon(map_style)}
        </button>
      </div>

      {/* Map Legend Controls */}
      <div className="map-legend-controls">
        <button 
          className="legend-btn"
          onClick={toggle_legend}
          title="Show Map Legend"
        >
          <Info size={18} />
        </button>
      </div>
      
      <div ref={map_ref} className="map-container" />
      
      {/* Route Selector - Floating at bottom */}
      {show_route_selector && route_alternatives.length > 0 && (
        <div className="route-selector">
          <div className="route-selector-header">
            <span>Routes:</span>
            <button 
              className="route-selector-close"
              onClick={close_route_selector}
              title="Close Route Selector"
            >
              ×
            </button>
          </div>
          <div className="route-options">
            {route_alternatives.length > 1 && (
              <div className="route-labels">
                {route_alternatives.map((_, index) => (
                  <span 
                    key={index}
                    className="route-label" 
                    onClick={() => toggle_directions_for_route(index)}
                    title={`Show directions for Route ${index + 1}`}
                  >
                    more
                  </span>
                ))}
              </div>
            )}
            <div className="route-buttons">
              {route_alternatives.map((route, index) => (
                <button
                  key={index}
                  className={`route-option ${selected_route_index === index ? 'selected' : ''}`}
                  onClick={() => select_route(index)}
                  title={`Route ${index + 1}: ${Math.round(route.summary.totalDistance / 1000 * 10) / 10} km, ${Math.round(route.summary.totalTime / 60)} min`}
                >
                  {index + 1}
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
      
      {/* Map Legend */}
      {show_legend && (
        <div className="map-legend">
          <div className="legend-header">
            <h4>Map Legend</h4>
            <button 
              className="legend-close-btn"
              onClick={toggle_legend}
              title="Close Legend"
            >
              <X size={16} />
            </button>
          </div>
          <div className="legend-item">
            <div className="legend-indicator vacant"></div>
            <span>Vacant Parking Space</span>
          </div>
          <div className="legend-item">
            <div className="legend-indicator occupied"></div>
            <span>Occupied Parking Space</span>
          </div>
          <div className="legend-item">
            <div className="legend-indicator reserved"></div>
            <span>Reserved Parking Space</span>
          </div>
          <div className="legend-item">
            <div className="legend-indicator user-location"></div>
            <span>Your Location</span>
          </div>
          {/* Street parking count legend removed */}
        </div>
      )}
    </div>
  );
};
