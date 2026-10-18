import { useState } from 'react';
import { StatusBar } from 'expo-status-bar';
import { Pressable, StyleSheet, Text, View } from 'react-native';

const VIBES = [
  { emoji: '🔥', label: 'On fire' },
  { emoji: '😌', label: 'Chill' },
  { emoji: '😴', label: 'Low energy' },
  { emoji: '😤', label: 'Stressed' },
  { emoji: '🤩', label: 'Excited' },
];

export default function App() {
  const [selected, setSelected] = useState(null);

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Vibe Check</Text>
      <Text style={styles.subtitle}>How are you feeling right now?</Text>

      <View style={styles.vibeRow}>
        {VIBES.map((vibe) => (
          <Pressable
            key={vibe.label}
            onPress={() => setSelected(vibe)}
            style={[
              styles.vibeButton,
              selected?.label === vibe.label && styles.vibeButtonSelected,
            ]}
          >
            <Text style={styles.vibeEmoji}>{vibe.emoji}</Text>
          </Pressable>
        ))}
      </View>

      <Text style={styles.result}>
        {selected ? `Vibe: ${selected.label} ${selected.emoji}` : 'Tap an emoji above'}
      </Text>

      <StatusBar style="auto" />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#fff',
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 24,
  },
  title: {
    fontSize: 36,
    fontWeight: 'bold',
    marginBottom: 8,
  },
  subtitle: {
    fontSize: 16,
    color: '#666',
    marginBottom: 32,
  },
  vibeRow: {
    flexDirection: 'row',
    gap: 12,
  },
  vibeButton: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: '#f0f0f0',
    alignItems: 'center',
    justifyContent: 'center',
  },
  vibeButtonSelected: {
    backgroundColor: '#dbeafe',
    borderWidth: 2,
    borderColor: '#3b82f6',
  },
  vibeEmoji: {
    fontSize: 26,
  },
  result: {
    marginTop: 32,
    fontSize: 18,
    fontWeight: '500',
  },
});
